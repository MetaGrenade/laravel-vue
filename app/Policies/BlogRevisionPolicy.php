<?php

namespace App\Policies;

use App\Models\BlogRevision;
use App\Models\User;

class BlogRevisionPolicy
{
    public function restore(?User $user, BlogRevision $revision): bool
    {
        if (! $user) {
            return false;
        }

        if (! $user->hasAnyRole(['admin', 'editor'])) {
            return false;
        }

        return $revision->blog_id !== null;
    }
}
