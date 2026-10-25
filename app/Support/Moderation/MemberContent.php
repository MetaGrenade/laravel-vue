<?php

namespace App\Support\Moderation;

use App\Enums\ReviewStatus;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\ModerationAction;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Forum\ForumAttachments;
use App\Support\Forum\ForumIndexCache;
use App\Support\Forum\ForumReview;
use App\Support\Forum\ThreadSolutions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Taking down what a member has posted, for a spam wave or a member who broke the rules (docs/moderation.md).
 *
 * Nothing is destroyed that a moderator could want back. A thread they started is unpublished (and any replies other people made to
 * it go out of sight with it); a reply of theirs in somebody else's thread is deleted (as a moderator deletes one); a blog comment or a
 * product review is rejected (as a moderator rejects one). What was still waiting in the review queue is rejected, as the queue does:
 * it was never public. The member is not told, and it is written to their history.
 */
class MemberContent
{
    public function __construct(
        private readonly Moderation $moderation,
        private readonly ForumReview $review,
        private readonly ForumIndexCache $cache,
        private readonly ThreadSolutions $solutions,
        private readonly ForumAttachments $attachments,
    ) {}

    /**
     * How much of theirs {@see takeDown()} would take down now.
     *
     * @return array{threads: int, replies: int, comments: int, reviews: int}
     */
    public function showing(User $member): array
    {
        return [
            'threads' => $this->threads($member)->count(),
            'replies' => $this->replies($member)->count(),
            'comments' => $this->comments($member)->count(),
            'reviews' => $this->reviews($member)->count(),
        ];
    }

    /**
     * Take down everything of theirs that is showing, and keep a note of it in their history (unless there was nothing).
     *
     * @throws ModerationRefused
     */
    public function takeDown(User $member, User $moderator, string $reason, ?string $note = null): ContentTakenDown
    {
        $refusal = $this->moderation->refusal($moderator, $member, 'cleanup');

        if ($refusal !== null) {
            throw new ModerationRefused($refusal);
        }

        $taken = DB::transaction(function () use ($member, $moderator, $reason, $note) {
            // Two moderators clearing out the same member at once: the second waits here and then finds nothing left.
            User::query()->lockForUpdate()->findOrFail($member->id);

            $now = now();

            $rejected = 0;

            foreach (ForumThread::query()->where('user_id', $member->id)->whereNotNull('held_at')->get() as $held) {
                $rejected += $this->review->rejectThread($held) ? 1 : 0;
            }

            // Taken before the updates. Read afterwards, every deleted reply, unpublished thread and rejected comment of theirs
            // would include what was already down, and the reports on that would be closed and counted as this clean-up's.
            $threadIds = $this->ids($this->threads($member)->where('is_published', true));
            $replyIds = $this->ids($this->replies($member));
            $commentIds = $this->ids($this->comments($member));

            // The delete below is a query, so the reply's deleting event (which would clear a solution) does not run.
            $this->solutions->forget($replyIds);
            $this->attachments->forget($replyIds);

            $taken = new ContentTakenDown(
                threadsHidden: $threadIds === [] ? 0 : ForumThread::query()->whereKey($threadIds)->update(['is_published' => false]),
                threadsRejected: $rejected,
                // One UPDATE that sets `deleted_at`, as a moderator's delete does for a single reply. Held replies are out of the
                // ordinary queries, so the scope that hides them has to be lifted or they would be left behind.
                replies: $replyIds === [] ? 0 : (int) ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereKey($replyIds)->delete(),
                comments: $commentIds === [] ? 0 : BlogComment::query()->whereKey($commentIds)->update(['status' => BlogComment::STATUS_REJECTED]),
                reviews: $this->reviews($member)->update([
                    'status' => ReviewStatus::Rejected->value,
                    'moderated_by' => $moderator->id,
                    'moderated_at' => $now,
                ]),
            );

            if ($taken->isEmpty()) {
                return $taken;
            }

            $taken = $taken->withReports($this->closeReports($threadIds, $replyIds, $commentIds, $moderator));

            ModerationAction::query()->create([
                'user_id' => $member->id,
                'actor_id' => $moderator->id,
                'type' => ModerationAction::REMOVAL,
                'reason' => trim($reason),
                'note' => $this->clean($note),
                'details' => $taken->toArray(),
                'notified' => false,
            ]);

            return $taken;
        });

        if (! $taken->isEmpty()) {
            // The updates above skip the model events that would empty the forum's cache.
            $this->cache->clear();
        }

        return $taken;
    }

    /**
     * Their threads that are showing or waiting for review (not those a moderator has already hidden).
     *
     * @return Builder<ForumThread>
     */
    private function threads(User $member): Builder
    {
        return ForumThread::query()
            ->where('user_id', $member->id)
            ->where(fn (Builder $query) => $query->where('is_published', true)->orWhereNotNull('held_at'));
    }

    /**
     * Their replies in other people's threads, held ones too. In their own threads they go out of sight with the thread.
     *
     * @return Builder<ForumPost>
     */
    private function replies(User $member): Builder
    {
        return ForumPost::query()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->where('user_id', $member->id)
            ->whereNotIn('forum_thread_id', ForumThread::query()->where('user_id', $member->id)->select('id'));
    }

    /**
     * @return Builder<BlogComment>
     */
    private function comments(User $member): Builder
    {
        return BlogComment::query()
            ->where('user_id', $member->id)
            ->whereIn('status', [BlogComment::STATUS_PENDING, BlogComment::STATUS_APPROVED]);
    }

    /**
     * @return Builder<ProductReview>
     */
    private function reviews(User $member): Builder
    {
        return ProductReview::query()
            ->where('user_id', $member->id)
            ->whereIn('status', [ReviewStatus::Pending->value, ReviewStatus::Published->value]);
    }

    /**
     * Mark the reports about what this clean-up just took down as dealt with. Returns how many.
     *
     * Only those items. A report on something that was already down stays waiting, for whoever deals with it.
     *
     * @param  list<int>  $threadIds
     * @param  list<int>  $replyIds
     * @param  list<int>  $commentIds
     */
    private function closeReports(array $threadIds, array $replyIds, array $commentIds, User $moderator): int
    {
        $done = ['status' => ForumPostReport::STATUS_REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $moderator->id];
        $closed = 0;

        if ($replyIds !== []) {
            $closed += ForumPostReport::query()
                ->where('status', ForumPostReport::STATUS_PENDING)
                ->whereIn('forum_post_id', $replyIds)
                ->update($done);
        }

        if ($threadIds !== []) {
            $closed += ForumThreadReport::query()
                ->where('status', ForumThreadReport::STATUS_PENDING)
                ->whereIn('forum_thread_id', $threadIds)
                ->update($done);
        }

        if ($commentIds !== []) {
            $closed += BlogCommentReport::query()
                ->where('status', BlogCommentReport::STATUS_PENDING)
                ->whereIn('blog_comment_id', $commentIds)
                ->update($done);
        }

        return $closed;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        $ids = [];

        foreach ($query->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
