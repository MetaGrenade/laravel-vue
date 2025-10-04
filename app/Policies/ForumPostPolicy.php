<?php

namespace App\Policies;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;

class ForumPostPolicy
{
    /**
     * Determine whether the user can view a post's revision history.
     */
    public function viewHistory(User $user, ForumPost $post): bool
    {
        if ($user->hasAnyRole(['admin', 'editor', 'moderator'])) {
            return true;
        }

        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can restore a revision.
     */
    public function restoreRevision(User $user, ForumPost $post): bool
    {
        if ($user->hasAnyRole(['admin', 'editor', 'moderator'])) {
            return true;
        }

        /** @var ForumThread|null $thread */
        $thread = $post->thread;

        if ($thread === null) {
            return false;
        }

        return $user->id === $post->user_id
            && $thread->is_published
            && !$thread->is_locked;
    }
}
