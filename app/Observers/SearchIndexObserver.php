<?php

namespace App\Observers;

use App\Models\ForumPost;
use App\Models\ForumThread;

/**
 * A reply is part of its thread's search document. Saving or deleting one
 * asks for that document again, once an engine is actually in use. The
 * database driver leaves Scout on the null engine, which does no work.
 */
class SearchIndexObserver
{
    public function saved(ForumPost $post): void
    {
        $this->refreshThread($post);
    }

    public function deleted(ForumPost $post): void
    {
        $this->refreshThread($post);
    }

    private function refreshThread(ForumPost $post): void
    {
        if (config('scout.driver') === 'null' || config('scout.driver') === null || config('scout.driver') === '') {
            return;
        }

        $thread = ForumThread::query()->find($post->forum_thread_id);

        if (! $thread instanceof ForumThread) {
            return;
        }

        if ($thread->shouldBeSearchable()) {
            $thread->searchable();

            return;
        }

        $thread->unsearchable();
    }
}
