<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;

class ForumThreadModerationController extends Controller
{
    public function publish(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (! $thread->is_published) {
            $thread->forceFill(['is_published' => true])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function unpublish(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_published) {
            $thread->forceFill(['is_published' => false])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function lock(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (! $thread->is_locked) {
            $thread->forceFill(['is_locked' => true])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function unlock(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_locked) {
            $thread->forceFill(['is_locked' => false])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function pin(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (! $thread->is_pinned) {
            $thread->forceFill(['is_pinned' => true])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function unpin(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_pinned) {
            $thread->forceFill(['is_pinned' => false])->save();
        }

        return $this->jsonThreadResponse($thread);
    }

    public function destroy(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $thread->delete();

        return response()->json(status: 204);
    }

    protected function jsonThreadResponse(ForumThread $thread): JsonResponse
    {
        return response()->json([
            'id' => $thread->id,
            'is_published' => $thread->is_published,
            'is_locked' => $thread->is_locked,
            'is_pinned' => $thread->is_pinned,
        ]);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }
}
