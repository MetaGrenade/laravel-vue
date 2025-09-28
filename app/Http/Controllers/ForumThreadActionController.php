<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumThread;
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
            ],
        );

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
            'page' => $validated['page'] ?? null,
        ])->with('success', 'Thread reported to the moderation team.');
    }
}
