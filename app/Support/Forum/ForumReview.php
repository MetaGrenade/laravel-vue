<?php

namespace App\Support\Forum;

use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Support\Facades\DB;

/**
 * What a moderator does with what automatic moderation held (docs/automod.md): approve it, and it is posted as if it had never been
 * held (everything that follows a post happens now, and not before); or reject it, and it is removed.
 *
 * Each looks at the row again under a lock, so two moderators who press at the same moment do it once: the second finds nothing held
 * and gets `false`.
 */
class ForumReview
{
    public function __construct(private readonly ForumPublisher $publisher) {}

    public function approveReply(ForumPost $post): bool
    {
        $held = DB::transaction(function () use ($post) {
            $held = $this->lockedReply($post);

            $held?->forceFill(['held_at' => null, 'held_reason' => null, 'held_by_rule_id' => null])->save();

            return $held;
        });

        if ($held === null) {
            return false;
        }

        $thread = $held->thread;
        $author = $held->author;

        if ($thread !== null && $author !== null) {
            $this->publisher->replyPublished($thread, $held, $author);
        }

        return true;
    }

    public function approveThread(ForumThread $thread): bool
    {
        $held = DB::transaction(function () use ($thread) {
            $held = ForumThread::query()->lockForUpdate()->find($thread->id);

            if ($held === null || $held->held_at === null) {
                return null;
            }

            // Being published is what ends the hold (see ForumThread::booted()).
            $held->forceFill(['is_published' => true, 'last_posted_at' => now()])->save();

            return $held;
        });

        if ($held === null) {
            return false;
        }

        $initialPost = $held->posts()->oldest('id')->first();
        $author = $held->author;

        if ($initialPost !== null && $author !== null) {
            $this->publisher->threadPublished($held, $initialPost, $author);
        }

        return true;
    }

    public function rejectReply(ForumPost $post): bool
    {
        return DB::transaction(function () use ($post) {
            $held = $this->lockedReply($post);

            $held?->delete();

            return $held !== null;
        });
    }

    public function rejectThread(ForumThread $thread): bool
    {
        return DB::transaction(function () use ($thread) {
            $held = ForumThread::query()->lockForUpdate()->find($thread->id);

            if ($held === null || $held->held_at === null) {
                return false;
            }

            $held->delete();

            return true;
        });
    }

    /**
     * The reply as it is now, if it is still held.
     */
    private function lockedReply(ForumPost $post): ?ForumPost
    {
        $held = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->lockForUpdate()->find($post->id);

        return $held !== null && $held->held_at !== null ? $held : null;
    }
}
