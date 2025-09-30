<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\ForumBoard;
use App\Models\ForumPostReport;
use App\Models\ForumThreadReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ForumReportController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $reasons = config('forum.report_reasons', []);

        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['all', 'thread', 'post'])],
            'status' => ['nullable', 'string', Rule::in(array_merge(['all'], ForumThreadReport::STATUSES))],
            'reason_category' => ['nullable', 'string', Rule::in(array_keys($reasons))],
            'board_id' => ['nullable', 'integer', 'exists:forum_boards,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $type = $validated['type'] ?? 'all';
        $status = $validated['status'] ?? ForumThreadReport::STATUS_PENDING;
        $reasonCategory = $validated['reason_category'] ?? null;
        $boardId = $validated['board_id'] ?? null;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $search = $search === '' ? null : $search;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $perPage = max(5, min(100, $perPage));
        $page = max(1, (int) $request->query('page', 1));

        $threadQuery = ForumThreadReport::query()
            ->with([
                'thread:id,forum_board_id,title,slug,is_locked,is_published',
                'thread.board:id,title,slug,forum_category_id',
                'reporter:id,nickname,email',
                'reviewer:id,nickname,email',
            ])
            ->whereHas('thread')
            ->orderByDesc('created_at');

        $postQuery = ForumPostReport::query()
            ->with([
                'post:id,forum_thread_id,user_id,body',
                'post.thread:id,forum_board_id,title,slug,is_locked,is_published',
                'post.thread.board:id,title,slug,forum_category_id',
                'post.author:id,nickname,email',
                'reporter:id,nickname,email',
                'reviewer:id,nickname,email',
            ])
            ->whereHas('post')
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $threadQuery->where('status', $status);
            $postQuery->where('status', $status);
        }

        if ($reasonCategory !== null) {
            $threadQuery->where('reason_category', $reasonCategory);
            $postQuery->where('reason_category', $reasonCategory);
        }

        if ($boardId !== null) {
            $threadQuery->whereHas('thread', function ($query) use ($boardId) {
                $query->where('forum_board_id', $boardId);
            });

            $postQuery->whereHas('post.thread', function ($query) use ($boardId) {
                $query->where('forum_board_id', $boardId);
            });
        }

        if ($search !== null) {
            $threadQuery->whereHas('thread', function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%");
            });

            $postQuery->whereHas('post.thread', function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%");
            });
        }

        $reports = collect();

        if ($type === 'all' || $type === 'thread') {
            $reports = $reports->merge(
                $threadQuery->get()->map(function (ForumThreadReport $report) {
                    $thread = $report->thread;

                    return [
                        'id' => $report->id,
                        'type' => 'thread',
                        'status' => $report->status,
                        'reason_category' => $report->reason_category,
                        'reason' => $report->reason,
                        'evidence_url' => $report->evidence_url,
                        'created_at' => optional($report->created_at)->toIso8601String(),
                        'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
                        'reporter' => $report->reporter ? [
                            'id' => $report->reporter->id,
                            'nickname' => $report->reporter->nickname,
                            'email' => $report->reporter->email,
                        ] : null,
                        'reviewer' => $report->reviewer ? [
                            'id' => $report->reviewer->id,
                            'nickname' => $report->reviewer->nickname,
                            'email' => $report->reviewer->email,
                        ] : null,
                        'thread' => $thread ? [
                            'id' => $thread->id,
                            'title' => $thread->title,
                            'slug' => $thread->slug,
                            'is_locked' => (bool) $thread->is_locked,
                            'is_published' => (bool) $thread->is_published,
                            'board' => $thread->board ? [
                                'id' => $thread->board->id,
                                'title' => $thread->board->title,
                                'slug' => $thread->board->slug,
                            ] : null,
                        ] : null,
                    ];
                })
            );
        }

        if ($type === 'all' || $type === 'post') {
            $reports = $reports->merge(
                $postQuery->get()->map(function (ForumPostReport $report) {
                    $post = $report->post;
                    $thread = $post?->thread;

                    return [
                        'id' => $report->id,
                        'type' => 'post',
                        'status' => $report->status,
                        'reason_category' => $report->reason_category,
                        'reason' => $report->reason,
                        'evidence_url' => $report->evidence_url,
                        'created_at' => optional($report->created_at)->toIso8601String(),
                        'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
                        'reporter' => $report->reporter ? [
                            'id' => $report->reporter->id,
                            'nickname' => $report->reporter->nickname,
                            'email' => $report->reporter->email,
                        ] : null,
                        'reviewer' => $report->reviewer ? [
                            'id' => $report->reviewer->id,
                            'nickname' => $report->reviewer->nickname,
                            'email' => $report->reviewer->email,
                        ] : null,
                        'post' => $post ? [
                            'id' => $post->id,
                            'body_preview' => Str::limit(strip_tags($post->body), 160),
                            'author' => $post->author ? [
                                'id' => $post->author->id,
                                'nickname' => $post->author->nickname,
                                'email' => $post->author->email,
                            ] : null,
                        ] : null,
                        'thread' => $thread ? [
                            'id' => $thread->id,
                            'title' => $thread->title,
                            'slug' => $thread->slug,
                            'is_locked' => (bool) $thread->is_locked,
                            'is_published' => (bool) $thread->is_published,
                            'board' => $thread->board ? [
                                'id' => $thread->board->id,
                                'title' => $thread->board->title,
                                'slug' => $thread->board->slug,
                            ] : null,
                        ] : null,
                    ];
                })
            );
        }

        $sortedReports = $reports
            ->sortByDesc(function (array $report) {
                return $report['created_at'] ?? '';
            })
            ->values();

        $total = $sortedReports->count();
        $sliced = $sortedReports->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $threadCounts = ForumThreadReport::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $postCounts = ForumPostReport::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusSummary = [];

        foreach (ForumThreadReport::STATUSES as $statusKey) {
            $threadCount = (int) ($threadCounts[$statusKey] ?? 0);
            $postCount = (int) ($postCounts[$statusKey] ?? 0);

            $statusSummary[$statusKey] = [
                'threads' => $threadCount,
                'posts' => $postCount,
                'total' => $threadCount + $postCount,
            ];
        }

        $boards = ForumBoard::query()
            ->orderBy('title')
            ->get(['id', 'title', 'slug']);

        return Inertia::render('acp/ForumReports', [
            'reports' => array_merge([
                'data' => $sliced->all(),
            ], $this->inertiaPagination($paginator)),
            'filters' => [
                'type' => $type,
                'status' => $status,
                'reason_category' => $reasonCategory,
                'board_id' => $boardId,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'reportReasons' => collect($reasons)
                ->map(fn (array $reason, string $key) => [
                    'value' => $key,
                    'label' => $reason['label'] ?? Str::title(str_replace('_', ' ', $key)),
                ])
                ->values()
                ->all(),
            'boards' => $boards->map(fn (ForumBoard $board) => [
                'id' => $board->id,
                'title' => $board->title,
                'slug' => $board->slug,
            ])->all(),
            'statusSummary' => $statusSummary,
        ]);
    }

    public function updateThread(Request $request, ForumThreadReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ForumThreadReport::STATUSES)],
            'moderation_action' => ['nullable', 'string', Rule::in(['none', 'lock_thread', 'unlock_thread', 'unpublish_thread', 'republish_thread'])],
        ]);

        $status = $validated['status'];
        $moderationAction = $validated['moderation_action'] ?? 'none';
        $moderationAction = $moderationAction === 'none' ? null : $moderationAction;

        $report->forceFill([
            'status' => $status,
            'reviewed_at' => $status === ForumThreadReport::STATUS_PENDING ? null : now(),
            'reviewed_by' => $status === ForumThreadReport::STATUS_PENDING ? null : optional($request->user())->id,
        ])->save();

        $thread = $report->thread;

        if ($thread) {
            match ($moderationAction) {
                'lock_thread' => $thread->forceFill(['is_locked' => true])->save(),
                'unlock_thread' => $thread->forceFill(['is_locked' => false])->save(),
                'unpublish_thread' => $thread->forceFill(['is_published' => false])->save(),
                'republish_thread' => $thread->forceFill(['is_published' => true])->save(),
                default => null,
            };
        }

        return back()->with('success', 'Thread report updated.');
    }

    public function updatePost(Request $request, ForumPostReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ForumPostReport::STATUSES)],
            'moderation_action' => ['nullable', 'string', Rule::in(['none', 'delete_post'])],
        ]);

        $status = $validated['status'];
        $moderationAction = $validated['moderation_action'] ?? 'none';
        $moderationAction = $moderationAction === 'none' ? null : $moderationAction;

        $report->forceFill([
            'status' => $status,
            'reviewed_at' => $status === ForumPostReport::STATUS_PENDING ? null : now(),
            'reviewed_by' => $status === ForumPostReport::STATUS_PENDING ? null : optional($request->user())->id,
        ])->save();

        if ($moderationAction === 'delete_post') {
            $report->post?->delete();
        }

        return back()->with('success', 'Post report updated.');
    }
}
