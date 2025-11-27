<?php

namespace App\Policies;

use App\Models\BlogComment;
use App\Models\User;

class BlogCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor', 'moderator']);
    }

    public function review(User $user, BlogComment $comment): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, BlogComment $comment): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $user->id === $comment->user_id;
    }

    public function delete(User $user, BlogComment $comment): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $user->id === $comment->user_id;
    }
}
