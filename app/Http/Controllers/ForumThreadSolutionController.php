<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Support\Forum\ThreadSolutions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForumThreadSolutionController extends Controller
{
    public function update(Request $request, ForumBoard $board, ForumThread $thread, ThreadSolutions $solutions): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $solutions->authorize($thread, $user);

        $validated = $request->validate([
            'post_id' => ['present', 'nullable', 'integer'],
        ]);

        $solutions->apply($thread, $validated['post_id'], $user);

        $marked = $validated['post_id'] !== null && $validated['post_id'] !== '';

        return redirect()->back()->with('success', $marked ? 'Reply marked as the solution.' : 'Solution cleared.');
    }
}
