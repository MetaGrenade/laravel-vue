<?php

namespace App\Observers;

use App\Models\ForumPost;
use App\Models\ForumThread;

/**
 * A reply is part of its thread's search document. Saving or deleting one
 * asks for that document again, once an engine is actually in use. The
 * database driver leaves Scout on the null engine, which does no work.
 *
 * Builder-level updates skip these events. {@see afterMemberContentTakedown()}
 * covers the moderation clean-up that unpublishes threads and soft-deletes
 * replies in bulk.
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

    /**
     * After a member's posts were taken down without model events: drop the
     * unpublished threads from the index, and rebuild every other thread that
     * lost a reply so that reply's text is gone.
     *
     * @param  list<int>  $unpublishedThreadIds
     * @param  list<int>  $replyThreadIds
     */
    public function afterMemberContentTakedown(array $unpublishedThreadIds, array $replyThreadIds): void
    {
        if ($this->idle()) {
            return;
        }

        $unpublishedThreadIds = array_values(array_unique(array_map(intval(...), $unpublishedThreadIds)));
        $replyThreadIds = array_values(array_unique(array_map(intval(...), $replyThreadIds)));

        if ($unpublishedThreadIds !== []) {
            ForumThread::query()
                ->whereKey($unpublishedThreadIds)
                ->orderBy('id')
                ->each(fn (ForumThread $thread) => $thread->unsearchable());
        }

        $refreshIds = array_values(array_diff($replyThreadIds, $unpublishedThreadIds));

        if ($refreshIds === []) {
            return;
        }

        ForumThread::query()
            ->whereKey($refreshIds)
            ->orderBy('id')
            ->each(fn (ForumThread $thread) => $this->syncThread($thread));
    }

    private function refreshThread(ForumPost $post): void
    {
        if ($this->idle()) {
            return;
        }

        $thread = ForumThread::query()->find($post->forum_thread_id);

        if (! $thread instanceof ForumThread) {
            return;
        }

        $this->syncThread($thread);
    }

    private function syncThread(ForumThread $thread): void
    {
        if ($thread->shouldBeSearchable()) {
            $thread->searchable();

            return;
        }

        $thread->unsearchable();
    }

    private function idle(): bool
    {
        $driver = config('scout.driver');

        return $driver === 'null' || $driver === null || $driver === '';
    }
}
