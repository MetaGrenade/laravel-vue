<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Support\Audit\AuditLogger;
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

            AuditLogger::log(
                'forum.thread.published',
                'Thread published',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_published' => [
                            'from' => false,
                            'to' => true,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread published successfully.');
    }

    public function unpublish(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_published) {
            $thread->forceFill(['is_published' => false])->save();

            AuditLogger::log(
                'forum.thread.unpublished',
                'Thread unpublished',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_published' => [
                            'from' => true,
                            'to' => false,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unpublished successfully.');
    }

    public function lock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_locked) {
            $thread->forceFill(['is_locked' => true])->save();

            AuditLogger::log(
                'forum.thread.locked',
                'Thread locked',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_locked' => [
                            'from' => false,
                            'to' => true,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread locked successfully.');
    }

    public function unlock(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_locked) {
            $thread->forceFill(['is_locked' => false])->save();

            AuditLogger::log(
                'forum.thread.unlocked',
                'Thread unlocked',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_locked' => [
                            'from' => true,
                            'to' => false,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread unlocked successfully.');
    }

    public function pin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if (!$thread->is_pinned) {
            $thread->forceFill(['is_pinned' => true])->save();

            AuditLogger::log(
                'forum.thread.pinned',
                'Thread pinned',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_pinned' => [
                            'from' => false,
                            'to' => true,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread pinned successfully.');
    }

    public function unpin(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        if ($thread->is_pinned) {
            $thread->forceFill(['is_pinned' => false])->save();

            AuditLogger::log(
                'forum.thread.unpinned',
                'Thread unpinned',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'is_pinned' => [
                            'from' => true,
                            'to' => false,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
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

        $originalTitle = $thread->title;

        if ($title !== $thread->title) {
            $thread->forceFill([
                'title' => $title,
            ])->save();

            AuditLogger::log(
                'forum.thread.title_updated',
                'Thread title updated',
                $this->auditThreadContext($board, $thread, [
                    'changes' => [
                        'title' => [
                            'from' => $originalTitle,
                            'to' => $title,
                        ],
                    ],
                ]),
                $request->user(),
                $thread,
            );
        }

        return $this->redirectAfterAction($request, $board, $thread, 'Thread title updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        AuditLogger::log(
            'forum.thread.deleted',
            'Thread deleted',
            $this->auditThreadContext($board, $thread),
            $request->user(),
            $thread,
        );

        $thread->delete();

        return $this->redirectToBoard($request, $board, 'Thread deleted successfully.');
    }

    private function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }

    private function auditThreadContext(ForumBoard $board, ForumThread $thread, array $extra = []): array
    {
        return array_merge([
            'board_id' => $board->id,
            'board_slug' => $board->slug,
            'thread_id' => $thread->id,
            'thread_slug' => $thread->slug,
            'thread_title' => $thread->title,
        ], $extra);
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
}
