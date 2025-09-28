<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForumThreadModerationController extends Controller
{
    public function publish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_published) {
            $thread->forceFill(['is_published' => true])->save();
        }

        return $this->redirectToBoard($board, 'Thread published successfully.');
    }

    public function unpublish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_published) {
            $thread->forceFill(['is_published' => false])->save();
        }

        return $this->redirectToBoard($board, 'Thread unpublished successfully.');
    }

    public function lock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_locked) {
            $thread->forceFill(['is_locked' => true])->save();
        }

        return $this->redirectToBoard($board, 'Thread locked successfully.');
    }

    public function unlock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_locked) {
            $thread->forceFill(['is_locked' => false])->save();
        }

        return $this->redirectToBoard($board, 'Thread unlocked successfully.');
    }

    public function update(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $thread->forceFill([
            'title' => $validated['title'],
        ])->save();

        return $this->redirectToBoard($board, 'Thread title updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $thread->delete();

        return redirect()->route('forum.boards.show', $board)
            ->with('success', 'Thread deleted successfully.');
    }

    private function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }

    private function redirectToBoard(ForumBoard $board, string $message): RedirectResponse
    {
        return redirect()->route('forum.boards.show', $board)
            ->with('success', $message);
    }
}
