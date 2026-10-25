<?php

namespace App\Support\Forum;

use App\Models\ForumPost;
use App\Models\ForumThread;

/**
 * What came of somebody posting in the forum: the thread, the post, and whether automatic moderation held it for a moderator
 * (docs/automod.md) instead of letting it out.
 */
final class ForumSubmission
{
    public function __construct(
        public readonly ForumThread $thread,
        public readonly ForumPost $post,
        public readonly bool $held,
    ) {}
}
