<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\NotificationChannelPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ForumPostController extends Controller
{
    public function store(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        abort_if($thread->is_locked || !$thread->is_published, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $body = trim($validated['body']);
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Reply cannot be empty.',
            ]);
        }

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $thread->forceFill([
            'last_posted_at' => Carbon::now(),
            'last_post_user_id' => $user->id,
        ])->save();

        ForumThreadRead::updateOrCreate(
            [
                'forum_thread_id' => $thread->id,
                'user_id' => $user->id,
            ],
            [
                'last_read_post_id' => $post->id,
                'last_read_at' => $post->created_at ?? now(),
            ],
        );

        $thread->loadMissing('board');

        $mentionedUsers = $this->resolveMentionedUsers($body, $user->id);

        if ($mentionedUsers->isNotEmpty()) {
            $post->mentions()->sync($mentionedUsers->pluck('id')->all());
            $this->notifyMentionedUsers($mentionedUsers, $thread, $post);
        }

        $subscribers = $thread->subscribers()
            ->where('users.id', '!=', $user->id)
            ->get();

        if ($subscribers->isNotEmpty()) {
            $subscribers->unique('id')->each(function (User $subscriber) use ($thread, $post) {
                $channels = NotificationChannelPreferences::resolveChannels($subscriber, 'forum_subscription');

                if ($channels === []) {
                    return;
                }

                $notification = new ForumThreadUpdated($thread, $post);

                $synchronousChannels = array_values(array_intersect($channels, ['database']));
                $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

                if ($synchronousChannels !== []) {
                    Notification::sendNow($subscriber, $notification->withChannels($synchronousChannels));
                }

                if ($queuedChannels !== []) {
                    Notification::send($subscriber, $notification->withChannels($queuedChannels));
                }
            });
        }

        $postCount = $thread->posts()->count();
        $perPage = 10;
        $lastPage = (int) ceil($postCount / max($perPage, 1));

        $parameters = [
            'board' => $board->slug,
            'thread' => $thread->slug,
        ];

        if ($lastPage > 1) {
            $parameters['page'] = $lastPage;
        }

        return redirect()
            ->route('forum.threads.show', $parameters)
            ->withFragment('post-' . $post->id)
            ->with('success', 'Reply posted successfully.');
    }

    public function update(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
        $canEditAsAuthor = $user->id === $post->user_id && $thread->is_published && !$thread->is_locked;

        abort_unless($isModerator || $canEditAsAuthor, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $body = trim($validated['body']);
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Post content cannot be empty.',
            ]);
        }

        $previousMentionIds = $post->mentions()->pluck('users.id');

        if ($body !== $post->body) {
            $post->revisions()->create([
                'body' => $post->body,
                'edited_at' => $post->edited_at,
                'edited_by_id' => $user->id,
            ]);
        }

        $post->forceFill([
            'body' => $body,
            'edited_at' => Carbon::now(),
        ])->save();

        $mentionedUsers = $this->resolveMentionedUsers($body, $user->id);

        $post->mentions()->sync($mentionedUsers->pluck('id')->all());

        $newlyMentionedUsers = $mentionedUsers->filter(fn (User $mentioned) => !$previousMentionIds->contains($mentioned->id));

        if ($newlyMentionedUsers->isNotEmpty()) {
            $thread->loadMissing('board');
            $this->notifyMentionedUsers($newlyMentionedUsers, $thread, $post);
        }

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $canDelete = $user->id === $post->user_id || $user->hasAnyRole(['admin', 'editor', 'moderator']);

        abort_unless($canDelete, 403);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $post->delete();

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post removed successfully.');
    }

    public function report(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $reasons = config('forum.report_reasons', []);

        $validated = $request->validate([
            'reason_category' => ['required', 'string', Rule::in(array_keys($reasons))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'evidence_url' => ['nullable', 'string', 'max:2048', 'url'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        $reason = $reason === '' ? null : $reason;

        $evidenceUrl = isset($validated['evidence_url']) ? trim((string) $validated['evidence_url']) : null;
        $evidenceUrl = $evidenceUrl === '' ? null : $evidenceUrl;

        ForumPostReport::updateOrCreate(
            [
                'forum_post_id' => $post->id,
                'reporter_id' => $user->id,
            ],
            [
                'reason_category' => $validated['reason_category'],
                'reason' => $reason,
                'evidence_url' => $evidenceUrl,
                'status' => ForumPostReport::STATUS_PENDING,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ],
        );

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post reported to the moderation team.');
    }

    private function ensureHierarchy(ForumBoard $board, ForumThread $thread, ForumPost $post): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
        abort_if($post->forum_thread_id !== $thread->id, 404);
    }

    private function redirectToThread(ForumBoard $board, ForumThread $thread, ?int $page, string $message): RedirectResponse
    {
        $parameters = [
            'board' => $board->slug,
            'thread' => $thread->slug,
        ];

        if ($page) {
            $parameters['page'] = $page;
        }

        return redirect()->route('forum.threads.show', $parameters)
            ->with('success', $message);
    }

    /**
     * @return Collection<int, string>
     */
    private function extractMentionNicknames(string $body): Collection
    {
        $plainText = html_entity_decode(strip_tags($body));

        preg_match_all('/(?<![\\w@])@([A-Za-z0-9_.-]{2,50})/u', $plainText, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $nickname) => trim($nickname))
            ->filter(fn (string $nickname) => $nickname !== '')
            ->unique(fn (string $nickname) => mb_strtolower($nickname))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveMentionedUsers(string $body, int $authorId): Collection
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
     * @param Collection<int, User> $mentionedUsers
     */
    private function notifyMentionedUsers(Collection $mentionedUsers, ForumThread $thread, ForumPost $post): void
    {
        if ($mentionedUsers->isEmpty()) {
            return;
        }

        $notification = new ForumPostMentioned($thread, $post);

        Notification::sendNow($mentionedUsers, $notification->withChannels(['database']));
        Notification::send($mentionedUsers, $notification->withChannels(['mail']));
    }
}
