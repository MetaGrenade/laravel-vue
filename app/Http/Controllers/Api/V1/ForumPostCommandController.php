<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ForumPostCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forum\StoreForumPostRequest;
use App\Http\Requests\Api\V1\Forum\UpdateForumPostRequest;
use App\Http\Resources\Api\V1\ForumPostResource;
use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\Reputation\ReputationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ForumPostCommandController extends Controller
{
    public function __construct(private readonly ReputationManager $reputation)
    {
    }

    public function store(StoreForumPostRequest $request, ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = $request->user();

        abort_if($user === null, 401);
        abort_if($thread->is_locked || ! $thread->is_published, 403);

        $validated = $request->validated();

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
            $notification = new ForumThreadUpdated($thread, $post);

            $subscribers->each(function (User $subscriber) use ($notification): void {
                $subscriber->notifyThroughPreferences($notification, 'forums', ['database', 'mail', 'push']);
            });
        }

        ForumPostCreated::dispatch($thread, $post);

        $this->reputation->record('forum_post_created', $user, $post, [
            'thread_id' => $thread->id,
        ]);

        $post->loadMissing('author');

        return response()->json(new ForumPostResource($post), 201);
    }

    public function update(UpdateForumPostRequest $request, ForumBoard $board, ForumThread $thread, ForumPost $post): ForumPostResource
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 401);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
        $canEditAsAuthor = $user->id === $post->user_id && $thread->is_published && ! $thread->is_locked;

        abort_unless($isModerator || $canEditAsAuthor, 403);

        $validated = $request->validated();

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

        $newlyMentionedUsers = $mentionedUsers->filter(fn (User $mentioned) => ! $previousMentionIds->contains($mentioned->id));

        if ($newlyMentionedUsers->isNotEmpty()) {
            $thread->loadMissing('board');
            $this->notifyMentionedUsers($newlyMentionedUsers, $thread, $post);
        }

        $post->loadMissing('author');

        return new ForumPostResource($post);
    }

    protected function ensureHierarchy(ForumBoard $board, ForumThread $thread, ForumPost $post): void
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        abort_if($post->forum_thread_id !== $thread->id, 404);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }

    /**
     * @return Collection<int, string>
     */
    private function extractMentionNicknames(string $body): Collection
    {
        $plainText = html_entity_decode(strip_tags($body));

        preg_match_all('/(?<![\\\w@])@([A-Za-z0-9_.-]{2,50})/u', $plainText, $matches);

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

        $mentionedUsers->each(function (User $mentionedUser) use ($notification): void {
            $mentionedUser->notifyThroughPreferences($notification, 'forums', ['database', 'mail', 'push']);
        });
    }
}
