<?php

namespace App\Support\Forum;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one reply that answers a thread (docs/forum-solutions.md).
 */
class ThreadSolutions
{
    public function __construct(private readonly ForumIndexCache $cache) {}

    /**
     * A published thread, and only its author or a moderator. A lock stops new replies; it does not stop accepting an answer.
     */
    public function authorize(ForumThread $thread, User $user): void
    {
        abort_unless($thread->is_published, 403);

        abort_unless(
            $user->hasAnyRole(['admin', 'editor', 'moderator']) || $user->id === $thread->user_id,
            403,
        );
    }

    /**
     * @throws ValidationException
     */
    public function apply(ForumThread $thread, mixed $postId, User $user): void
    {
        $this->authorize($thread, $user);

        if ($postId === null || $postId === '') {
            $this->clear($thread);

            return;
        }

        if (! is_int($postId) && ! (is_string($postId) && ctype_digit($postId))) {
            throw ValidationException::withMessages([
                'post_id' => 'That reply is not in this thread.',
            ]);
        }

        $post = ForumPost::query()
            ->where('forum_thread_id', $thread->id)
            ->whereKey((int) $postId)
            ->first();

        if (! $post instanceof ForumPost) {
            throw ValidationException::withMessages([
                'post_id' => 'That reply is not in this thread.',
            ]);
        }

        // Include a deleted opening post. Leaving it out would make the next reply the opener, so it could no longer be the solution.
        $openerId = ForumPost::query()
            ->withTrashed()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->where('forum_thread_id', $thread->id)
            ->orderBy('id')
            ->value('id');

        if (is_numeric($openerId) && (int) $openerId === $post->id) {
            throw ValidationException::withMessages([
                'post_id' => 'The opening post cannot be the solution.',
            ]);
        }

        if ($thread->solved_post_id === $post->id) {
            return;
        }

        $thread->forceFill(['solved_post_id' => $post->id])->save();
    }

    /**
     * Drop the solution when those replies go away. A query that deletes them does not fire the model event that would do this,
     * and a soft delete does not clear the foreign key on its own.
     *
     * @param  list<int>  $postIds
     */
    public function forget(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        $updated = ForumThread::query()->whereIn('solved_post_id', $postIds)->update(['solved_post_id' => null]);

        if ($updated > 0) {
            $this->cache->clear();
        }
    }

    private function clear(ForumThread $thread): void
    {
        if ($thread->solved_post_id === null) {
            return;
        }

        $thread->forceFill(['solved_post_id' => null])->save();
    }
}
