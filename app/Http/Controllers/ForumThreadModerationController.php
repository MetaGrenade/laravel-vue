<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use function activity;

class ForumThreadModerationController extends Controller
{
    public function publish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_published) {
            $thread->forceFill(['is_published' => true])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.published',
                sprintf('Thread "%s" published', $thread->title),
                [
                    'old' => ['is_published' => false],
                    'attributes' => ['is_published' => true],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread published successfully.');
    }

    public function unpublish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_published) {
            $thread->forceFill(['is_published' => false])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.unpublished',
                sprintf('Thread "%s" unpublished', $thread->title),
                [
                    'old' => ['is_published' => true],
                    'attributes' => ['is_published' => false],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unpublished successfully.');
    }

    public function lock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_locked) {
            $thread->forceFill(['is_locked' => true])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.locked',
                sprintf('Thread "%s" locked', $thread->title),
                [
                    'old' => ['is_locked' => false],
                    'attributes' => ['is_locked' => true],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread locked successfully.');
    }

    public function unlock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_locked) {
            $thread->forceFill(['is_locked' => false])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.unlocked',
                sprintf('Thread "%s" unlocked', $thread->title),
                [
                    'old' => ['is_locked' => true],
                    'attributes' => ['is_locked' => false],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unlocked successfully.');
    }

    public function pin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_pinned) {
            $thread->forceFill(['is_pinned' => true])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.pinned',
                sprintf('Thread "%s" pinned', $thread->title),
                [
                    'old' => ['is_pinned' => false],
                    'attributes' => ['is_pinned' => true],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread pinned successfully.');
    }

    public function unpin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_pinned) {
            $thread->forceFill(['is_pinned' => false])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.unpinned',
                sprintf('Thread "%s" unpinned', $thread->title),
                [
                    'old' => ['is_pinned' => true],
                    'attributes' => ['is_pinned' => false],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unpinned successfully.');
    }

    public function update(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = $request->user();

        abort_if($user === null, 403);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
        $canEditAsAuthor = $user->id === $thread->user_id && $thread->is_published && !$thread->is_locked;

        abort_unless($isModerator || $canEditAsAuthor, 403);

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
            $previousTitle = $thread->title;

            $thread->forceFill([
                'title' => $title,
            ])->save();

            $this->logThreadEvent(
                $request,
                $board,
                $thread,
                'forum.thread.title_updated',
                sprintf('Thread "%s" retitled', $thread->title),
                [
                    'old' => ['title' => $previousTitle],
                    'attributes' => ['title' => $thread->title],
                ],
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread title updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $snapshot = [
            'id' => $thread->id,
            'title' => $thread->title,
            'slug' => $thread->slug,
        ];

        $thread->delete();

        $this->logThreadEvent(
            $request,
            $board,
            $thread,
            'forum.thread.deleted',
            sprintf('Thread "%s" deleted', $snapshot['title']),
            [
                'old' => $snapshot,
            ],
        );

        return $this->redirectToBoard($request, $board, 'Thread deleted successfully.');
    }

    private function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }

    private function redirectToBoard(Request $request, ForumBoard $board, string $message): RedirectResponse
    {
        $parameters = [
            'board' => $board->slug,
        ];

        $page = (int) $request->input('page');

        if ($page > 0) {
            $parameters['page'] = $page;
        }

        $search = $request->input('search');

        if (is_string($search)) {
            $search = trim($search);

            if ($search !== '') {
                $parameters['search'] = $search;
            }
        }

        return redirect()->route('forum.boards.show', $parameters)
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

        return $this->redirectToBoard($request, $board, $message);
    }

    private function logThreadEvent(
        Request $request,
        ForumBoard $board,
        ForumThread $thread,
        string $event,
        string $message,
        array $properties = []
    ): void {
        $actor = $request->user();

        if (! $actor) {
            return;
        }

        $baseProperties = [
            'attributes' => array_merge([
                'thread_id' => $thread->id,
                'thread_slug' => $thread->slug,
                'thread_title' => $thread->title,
                'board_id' => $board->id,
                'board_slug' => $board->slug,
            ], $properties['attributes'] ?? []),
        ];

        if (isset($properties['old'])) {
            $baseProperties['old'] = $properties['old'];
        }

        activity('forum')
            ->event($event)
            ->performedOn($thread)
            ->causedBy($actor)
            ->withProperties($baseProperties)
            ->log($message);
    }
}
