<?php

namespace App\Policies;

use App\Models\BlogComment;
use App\Models\User;

class BlogCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('blogs.acp.view');
    }

    public function review(User $user, BlogComment $comment): bool
    {
        return $user->can('blogs.acp.edit');
    }

    public function update(User $user, BlogComment $comment): bool
    {
        if ($user->can('blogs.acp.edit')) {
            return true;
        }

        return $user->id === $comment->user_id;
    }

    public function delete(User $user, BlogComment $comment): bool
    {
        if ($user->can('blogs.acp.delete')) {
            return true;
        }

        return $user->id === $comment->user_id;
    }

    public function report(User $user, BlogComment $comment): bool
    {
        return ! $user->is_banned && $user->id !== $comment->user_id;
    }
}
