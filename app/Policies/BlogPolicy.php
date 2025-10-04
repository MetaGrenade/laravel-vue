<?php

namespace App\Policies;

use App\Models\Blog;
use App\Models\User;

class BlogPolicy
{
    public function viewRevisions(User $user, Blog $blog): bool
    {
        if ($user->id === $blog->user_id) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'moderator']);
    }

    public function restoreRevision(User $user, Blog $blog): bool
    {
        if ($user->id === $blog->user_id) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'moderator']);
    }
}
