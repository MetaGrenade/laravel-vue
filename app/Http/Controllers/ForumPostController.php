<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ForumPostController extends Controller
{
    public function store(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        abort_if($thread->is_locked || !$thread->is_published, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $body = trim($validated['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Reply cannot be empty.',
            ]);
        }

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $thread->forceFill([
            'last_posted_at' => Carbon::now(),
            'last_post_user_id' => $user->id,
        ])->save();

        $postCount = $thread->posts()->count();
        $perPage = 10;
        $lastPage = (int) ceil($postCount / max($perPage, 1));

        $parameters = [
            'board' => $board->slug,
            'thread' => $thread->slug,
        ];

        if ($lastPage > 1) {
            $parameters['page'] = $lastPage;
        }

        return redirect()
            ->route('forum.threads.show', $parameters)
            ->withFragment('post-' . $post->id)
            ->with('success', 'Reply posted successfully.');
    }

    public function update(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $canEdit = $user->id === $post->user_id || $user->hasAnyRole(['admin', 'editor', 'moderator']);

        abort_unless($canEdit, 403);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $post->forceFill([
            'body' => $validated['body'],
            'edited_at' => Carbon::now(),
        ])->save();

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $canDelete = $user->id === $post->user_id || $user->hasAnyRole(['admin', 'editor', 'moderator']);

        abort_unless($canDelete, 403);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $post->delete();

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post removed successfully.');
    }

    public function report(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 403);

        $reasons = config('forum.report_reasons', []);

        $validated = $request->validate([
            'reason_category' => ['required', 'string', Rule::in(array_keys($reasons))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'evidence_url' => ['nullable', 'string', 'max:2048', 'url'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        $reason = $reason === '' ? null : $reason;

        $evidenceUrl = isset($validated['evidence_url']) ? trim((string) $validated['evidence_url']) : null;
        $evidenceUrl = $evidenceUrl === '' ? null : $evidenceUrl;

        ForumPostReport::updateOrCreate(
            [
                'forum_post_id' => $post->id,
                'reporter_id' => $user->id,
            ],
            [
                'reason_category' => $validated['reason_category'],
                'reason' => $reason,
                'evidence_url' => $evidenceUrl,
            ],
        );

        return $this->redirectToThread($board, $thread, $validated['page'] ?? null, 'Post reported to the moderation team.');
    }

    private function ensureHierarchy(ForumBoard $board, ForumThread $thread, ForumPost $post): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
        abort_if($post->forum_thread_id !== $thread->id, 404);
    }

    private function redirectToThread(ForumBoard $board, ForumThread $thread, ?int $page, string $message): RedirectResponse
    {
        $parameters = [
            'board' => $board->slug,
            'thread' => $thread->slug,
        ];

        if ($page) {
            $parameters['page'] = $page;
        }

        return redirect()->route('forum.threads.show', $parameters)
            ->with('success', $message);
    }
}
