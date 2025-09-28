<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ForumThreadModerationController extends Controller
{
    public function publish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_published) {
            $thread->forceFill(['is_published' => true])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread published successfully.');
    }

    public function unpublish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_published) {
            $thread->forceFill(['is_published' => false])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unpublished successfully.');
    }

    public function lock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_locked) {
            $thread->forceFill(['is_locked' => true])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread locked successfully.');
    }

    public function unlock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_locked) {
            $thread->forceFill(['is_locked' => false])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unlocked successfully.');
    }

    public function pin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_pinned) {
            $thread->forceFill(['is_pinned' => true])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread pinned successfully.');
    }

    public function unpin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_pinned) {
            $thread->forceFill(['is_pinned' => false])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unpinned successfully.');
    }

    public function update(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $title = trim($validated['title']);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'The thread title cannot be empty.',
            ]);
        }

        if ($title !== $thread->title) {
            $thread->forceFill([
                'title' => $title,
            ])->save();
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread title updated successfully.');
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

    private function redirectAfterAction(Request $request, ForumBoard $board, ForumThread $thread, string $message): RedirectResponse
    {
        if ($request->boolean('redirect_to_thread')) {
            $parameters = [
                'board' => $board->slug,
                'thread' => $thread->slug,
            ];

            $page = (int) $request->input('page');

            if ($page > 0) {
                $parameters['page'] = $page;
            }

            return redirect()->route('forum.threads.show', $parameters)
                ->with('success', $message);
        }

        return $this->redirectToBoard($board, $message);
    }
}
