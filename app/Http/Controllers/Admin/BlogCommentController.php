<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BlogCommentController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BlogComment::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_merge(['all'], BlogCommentReport::STATUSES))],
            'reason_category' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(['newest', 'oldest', 'most_reported'])],
        ]);

        $status = $validated['status'] ?? BlogCommentReport::STATUS_PENDING;
        $reasonCategory = isset($validated['reason_category']) ? trim((string) $validated['reason_category']) : null;
        $reasonCategory = $reasonCategory === '' ? null : $reasonCategory;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $search = $search === '' ? null : $search;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $perPage = max(5, min(100, $perPage));
        $sort = $validated['sort'] ?? 'newest';

        $commentsQuery = BlogComment::query()
            ->select('blog_comments.*')
            ->with([
                'blog:id,title,slug,status',
                'user:id,nickname,email,is_banned',
                'reports' => function ($query) use ($status, $reasonCategory) {
                    $query
                        ->when($status !== 'all', fn ($inner) => $inner->where('status', $status))
                        ->when($reasonCategory !== null, fn ($inner) => $inner->where('reason_category', $reasonCategory))
                        ->with([
                            'reporter:id,nickname,email',
                            'reviewer:id,nickname,email',
                        ])
                        ->orderByDesc('created_at');
                },
            ])
            ->withCount([
                'reports as total_reports_count',
                'reports as filtered_reports_count' => function ($query) use ($status, $reasonCategory) {
                    $query
                        ->when($status !== 'all', fn ($inner) => $inner->where('status', $status))
                        ->when($reasonCategory !== null, fn ($inner) => $inner->where('reason_category', $reasonCategory));
                },
                'reports as pending_reports_count' => fn ($query) => $query->where('status', BlogCommentReport::STATUS_PENDING),
            ])
            ->withMax('reports as latest_reported_at', 'created_at')
            ->whereHas('reports', function ($query) use ($status, $reasonCategory) {
                $query
                    ->when($status !== 'all', fn ($inner) => $inner->where('status', $status))
                    ->when($reasonCategory !== null, fn ($inner) => $inner->where('reason_category', $reasonCategory));
            });

        if ($search !== null) {
            $commentsQuery->where('body', 'like', "%{$search}%");
        }

        $commentsQuery->when($sort === 'most_reported', function ($query) {
            $query
                ->orderByDesc('filtered_reports_count')
                ->orderByDesc('total_reports_count')
                ->orderByDesc('latest_reported_at');
        }, function ($query) use ($sort) {
            $query->orderBy(
                'latest_reported_at',
                $sort === 'oldest' ? 'asc' : 'desc',
            );
        });

        $comments = $commentsQuery->paginate($perPage)->withQueryString();

        $formatter = DateFormatter::for($request->user());

        $items = $comments->getCollection()
            ->map(function (BlogComment $comment) use ($request, $formatter) {
                $blog = $comment->blog;
                $reports = $comment->reports;
                $latestReport = $reports->first();

                $reporters = $reports
                    ->map(fn (BlogCommentReport $report) => $report->reporter)
                    ->filter()
                    ->unique('id')
                    ->values()
                    ->map(fn ($reporter) => [
                        'id' => $reporter->id,
                        'nickname' => $reporter->nickname,
                        'email' => $reporter->email,
                    ])
                    ->all();

                return [
                    'id' => $comment->id,
                    'reports_count' => (int) ($comment->filtered_reports_count ?? $reports->count()),
                    'total_reports_count' => (int) ($comment->total_reports_count ?? $reports->count()),
                    'pending_reports_count' => (int) $comment->pending_reports_count,
                    'latest_reported_at' => $formatter->iso($comment->latest_reported_at),
                    'report_ids' => $reports->pluck('id')->values()->all(),
                    'latest_report' => $latestReport ? [
                        'status' => $latestReport->status,
                        'reason_category' => $latestReport->reason_category,
                        'reason' => $latestReport->reason,
                        'evidence_url' => $latestReport->evidence_url,
                        'created_at' => $formatter->iso($latestReport->created_at),
                        'reporter' => $latestReport->reporter ? [
                            'id' => $latestReport->reporter->id,
                            'nickname' => $latestReport->reporter->nickname,
                            'email' => $latestReport->reporter->email,
                        ] : null,
                        'reviewer' => $latestReport->reviewer ? [
                            'id' => $latestReport->reviewer->id,
                            'nickname' => $latestReport->reviewer->nickname,
                            'email' => $latestReport->reviewer->email,
                        ] : null,
                    ] : null,
                    'reporters' => $reporters,
                    'comment' => [
                        'id' => $comment->id,
                        'body' => $comment->body,
                        'body_preview' => Str::limit(strip_tags($comment->body), 140),
                        'status' => $comment->status,
                        'is_flagged' => (bool) $comment->is_flagged,
                        'can' => [
                            'update' => $request->user()?->can('update', $comment) ?? false,
                            'review' => $request->user()?->can('review', $comment) ?? false,
                            'delete' => $request->user()?->can('delete', $comment) ?? false,
                        ],
                    ],
                    'blog' => $blog ? [
                        'id' => $blog->id,
                        'title' => $blog->title,
                        'slug' => $blog->slug,
                        'status' => $blog->status,
                    ] : null,
                    'author' => $comment->user ? [
                        'id' => $comment->user->id,
                        'nickname' => $comment->user->nickname,
                        'email' => $comment->user->email,
                        'is_banned' => (bool) $comment->user->is_banned,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $reasons = collect(config('forum.report_reasons', []))
            ->map(fn (array $reason, string $key) => [
                'value' => $key,
                'label' => $reason['label'] ?? Str::title(str_replace('_', ' ', $key)),
            ])
            ->values()
            ->all();

        return Inertia::render('acp/BlogComments', [
            'reports' => [
                'data' => $items,
                ...$this->inertiaPagination($comments),
            ],
            'filters' => [
                'status' => $status,
                'reason_category' => $reasonCategory,
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
            ],
            'statuses' => BlogCommentReport::STATUSES,
            'reportReasons' => $reasons,
            'commentStatuses' => BlogComment::STATUSES,
        ]);
    }

    public function update(Request $request, BlogComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', Rule::in(BlogComment::STATUSES)],
            'is_flagged' => ['sometimes', 'boolean'],
        ]);

        $body = trim($validated['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Comment cannot be empty.',
            ]);
        }

        $comment->body = $body;

        if (array_key_exists('status', $validated)) {
            $this->authorize('review', $comment);
            $comment->status = (string) $validated['status'];
        }

        if (array_key_exists('is_flagged', $validated)) {
            $this->authorize('review', $comment);
            $comment->is_flagged = (bool) $validated['is_flagged'];
        }

        $comment->save();

        return back()->with('success', 'Comment updated successfully.');
    }

    public function destroy(Request $request, BlogComment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back()->with('success', 'Comment deleted successfully.');
    }

    public function bulkUpdateReportStatus(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', BlogComment::class);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(BlogCommentReport::STATUSES)],
            'reports' => ['required', 'array', 'min:1'],
            'reports.*' => ['required', 'integer'],
        ]);

        $reportIds = collect($validated['reports'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($reportIds->isEmpty()) {
            return back()->with('success', 'No blog comment reports required updates.');
        }

        $timestamp = now();
        $userId = optional($request->user())->id;
        $updatedCount = 0;
        $affectedCommentIds = [];

        BlogCommentReport::query()
            ->whereIn('id', $reportIds)
            ->get()
            ->each(function (BlogCommentReport $report) use ($validated, $timestamp, $userId, &$updatedCount, &$affectedCommentIds) {
                $report->forceFill([
                    'status' => $validated['status'],
                    'reviewed_at' => $validated['status'] === BlogCommentReport::STATUS_PENDING ? null : $timestamp,
                    'reviewed_by' => $validated['status'] === BlogCommentReport::STATUS_PENDING ? null : $userId,
                ])->save();

                if ($report->wasChanged(['status', 'reviewed_at', 'reviewed_by'])) {
                    $updatedCount++;
                }

                if ($report->blog_comment_id) {
                    $affectedCommentIds[] = $report->blog_comment_id;
                }
            });

        $this->refreshCommentFlags($affectedCommentIds);

        return back()->with(
            'success',
            match ($updatedCount) {
                0 => 'No blog comment reports required updates.',
                1 => 'Updated 1 blog comment report.',
                default => "Updated {$updatedCount} blog comment reports.",
            },
        );
    }

    private function refreshCommentFlags(array $commentIds): void
    {
        if (empty($commentIds)) {
            return;
        }

        BlogComment::query()
            ->whereIn('id', array_unique($commentIds))
            ->get()
            ->each(function (BlogComment $comment) {
                $hasPendingReports = $comment->reports()
                    ->where('status', BlogCommentReport::STATUS_PENDING)
                    ->exists();

                $comment->forceFill(['is_flagged' => $hasPendingReports])->save();
            });
    }
}
