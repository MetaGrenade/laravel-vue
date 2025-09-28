<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForumThreadActionController extends Controller
{
    public function report(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        ForumThreadReport::updateOrCreate(
            [
                'forum_thread_id' => $thread->id,
                'reporter_id' => $user->id,
            ],
            [
                'reason' => $validated['reason'] ?? null,
            ],
        );

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
            'page' => $validated['page'] ?? null,
        ])->with('success', 'Thread reported to the moderation team.');
    }
}
