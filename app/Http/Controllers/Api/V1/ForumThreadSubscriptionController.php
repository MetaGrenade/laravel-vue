<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;

class ForumThreadSubscriptionController extends Controller
{
    public function store(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = request()->user();

        abort_if($user === null, 401);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);

        if (! $thread->is_published && ! $isModerator) {
            abort(403);
        }

        $thread->subscriptions()->firstOrCreate([
            'user_id' => $user->id,
        ]);

        return response()->json(['subscribed' => true]);
    }

    public function destroy(ForumBoard $board, ForumThread $thread): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = request()->user();

        abort_if($user === null, 401);

        $thread->subscriptions()
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }
}
