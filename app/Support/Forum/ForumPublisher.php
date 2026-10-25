<?php

namespace App\Support\Forum;

use App\Events\ForumPostCreated;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\Reputation\ReputationManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What follows content that is now out in the open in the forum: the thread is bumped, the author has read it, the people mentioned and
 * the people following the thread are told, the page is told live, and the author earns reputation.
 *
 * It is done when a reply or a thread is posted, or, for one that automatic moderation held, when a moderator approves it (docs/automod.md),
 * and so it lives here and not in each of the controllers that post.
 */
class ForumPublisher
{
    public function __construct(
        private readonly ReputationManager $reputation,
        private readonly IgnoredMembers $ignored,
    ) {}

    /**
     * A reply is out in the open.
     */
    public function replyPublished(ForumThread $thread, ForumPost $post, User $author): void
    {
        $thread->forceFill([
            'last_posted_at' => Carbon::now(),
            'last_post_user_id' => $author->id,
        ])->save();

        $this->markRead($thread, $post, $author);

        $thread->loadMissing('board');

        $mentionedUsers = $this->resolveMentionedUsers((string) $post->body, $author->id);

        if ($mentionedUsers->isNotEmpty()) {
            $post->mentions()->sync($mentionedUsers->pluck('id')->all());
            $this->notifyMentionedUsers($mentionedUsers, $thread, $post);
        }

        $quiet = array_flip($this->ignored->quietFor($author));

        $subscribers = $thread->subscribers()
            ->where('users.id', '!=', $author->id)
            ->get()
            ->reject(fn (User $subscriber): bool => isset($quiet[$subscriber->id]))
            ->values();

        if ($subscribers->isNotEmpty()) {
            $notification = new ForumThreadUpdated($thread, $post);

            $subscribers->each(function (User $subscriber) use ($notification): void {
                $subscriber->notifyThroughPreferences($notification, 'forums', ['database', 'mail', 'push']);
            });
        }

        ForumPostCreated::dispatch($thread, $post);

        $this->reputation->record('forum_post_created', $author, $post, [
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * A thread, and the post that starts it, are out in the open.
     */
    public function threadPublished(ForumThread $thread, ForumPost $initialPost, User $author): void
    {
        $this->markRead($thread, $initialPost, $author);

        $this->reputation->record('forum_post_created', $author, $initialPost, [
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * The people a post names with `@nickname` (not its author).
     *
     * @return Collection<int, User>
     */
    public function resolveMentionedUsers(string $body, int $authorId): Collection
    {
        $nicknames = $this->extractMentionNicknames($body);

        if ($nicknames->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('nickname', $nicknames)
            ->where('id', '!=', $authorId)
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, User>  $mentionedUsers
     */
    public function notifyMentionedUsers(Collection $mentionedUsers, ForumThread $thread, ForumPost $post): void
    {
        $post->loadMissing('author');
        $author = $post->author;

        if ($author instanceof User) {
            $quiet = array_flip($this->ignored->quietFor($author));
            $mentionedUsers = $mentionedUsers
                ->reject(fn (User $mentioned): bool => isset($quiet[$mentioned->id]))
                ->values();
        }

        if ($mentionedUsers->isEmpty()) {
            return;
        }

        $notification = new ForumPostMentioned($thread, $post);

        $mentionedUsers->each(function (User $mentionedUser) use ($notification): void {
            $mentionedUser->notifyThroughPreferences($notification, 'forums', ['database', 'mail', 'push']);
        });
    }

    private function markRead(ForumThread $thread, ForumPost $post, User $author): void
    {
        ForumThreadRead::updateOrCreate(
            [
                'forum_thread_id' => $thread->id,
                'user_id' => $author->id,
            ],
            [
                'last_read_post_id' => $post->id,
                'last_read_at' => $post->created_at ?? now(),
            ],
        );
    }

    /**
     * @return Collection<int, non-empty-string>
     */
    private function extractMentionNicknames(string $body): Collection
    {
        $plainText = html_entity_decode(strip_tags($body));

        preg_match_all('/(?<![\\w@])@([A-Za-z0-9_.-]{2,50})/u', $plainText, $matches);

        return collect($matches[1])
            ->map(fn (string $nickname) => trim($nickname))
            ->filter(fn (string $nickname) => $nickname !== '')
            ->unique(fn (string $nickname) => mb_strtolower($nickname))
            ->values();
    }
}
