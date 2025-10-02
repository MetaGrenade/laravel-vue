<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Models\ForumThreadReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ForumThreadActionController extends Controller
{
    public function report(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

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

        ForumThreadReport::updateOrCreate(
            [
                'forum_thread_id' => $thread->id,
                'reporter_id' => $user->id,
            ],
            [
                'reason_category' => $validated['reason_category'],
                'reason' => $reason,
                'evidence_url' => $evidenceUrl,
                'status' => ForumThreadReport::STATUS_PENDING,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ],
        );

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
            'page' => $validated['page'] ?? null,
        ])->with('success', 'Thread reported to the moderation team.');
    }

    public function markAsRead(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);

        if (!$thread->is_published && !$isModerator) {
            abort(403);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $latestPost = $thread->latestPost()
            ->select('forum_posts.id', 'forum_posts.created_at')
            ->first();

        $readAt = $latestPost?->created_at ?? now();

        if ($thread->last_posted_at !== null && $thread->last_posted_at->greaterThan($readAt)) {
            $readAt = $thread->last_posted_at;
        }

        ForumThreadRead::updateOrCreate(
            [
                'forum_thread_id' => $thread->id,
                'user_id' => $user->id,
            ],
            [
                'last_read_post_id' => $latestPost?->id,
                'last_read_at' => $readAt,
            ],
        );

        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $search = $search === '' ? null : $search;

        $redirectParameters = [
            'board' => $board->slug,
            'page' => $validated['page'] ?? null,
        ];

        if ($search !== null) {
            $redirectParameters['search'] = $search;
        }

        return redirect()->route('forum.boards.show', $redirectParameters)
            ->with('success', 'Thread marked as read.');
    }

    public function subscribe(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);

        if (!$thread->is_published && !$isModerator) {
            abort(403);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $thread->subscriptions()->firstOrCreate([
            'user_id' => $user->id,
        ]);

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
            'page' => $validated['page'] ?? null,
        ])->with('success', 'You are now following this thread.');
    }

    public function unsubscribe(Request $request, ForumBoard $board, ForumThread $thread): RedirectResponse
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $thread->subscriptions()
            ->where('user_id', $user->id)
            ->delete();

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
            'page' => $validated['page'] ?? null,
        ])->with('success', 'Thread unfollowed successfully.');
    }
}
