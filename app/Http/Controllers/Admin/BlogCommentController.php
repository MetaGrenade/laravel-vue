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
        ]);

        $status = $validated['status'] ?? BlogCommentReport::STATUS_PENDING;
        $reasonCategory = isset($validated['reason_category']) ? trim((string) $validated['reason_category']) : null;
        $reasonCategory = $reasonCategory === '' ? null : $reasonCategory;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $search = $search === '' ? null : $search;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $perPage = max(5, min(100, $perPage));

        $query = BlogCommentReport::query()
            ->with([
                'comment:id,blog_id,user_id,body,status,is_flagged',
                'comment.blog:id,title,slug,status',
                'comment.user:id,nickname,email,is_banned',
                'reporter:id,nickname,email',
                'reviewer:id,nickname,email',
            ])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($reasonCategory !== null) {
            $query->where('reason_category', $reasonCategory);
        }

        if ($search !== null) {
            $query->whereHas('comment', function ($query) use ($search) {
                $query->where('body', 'like', "%{$search}%");
            });
        }

        $reports = $query->paginate($perPage)->withQueryString();

        $formatter = DateFormatter::for($request->user());
        $items = $reports->getCollection()
            ->map(function (BlogCommentReport $report) use ($request, $formatter) {
                $comment = $report->comment;
                $blog = $comment?->blog;
                $reporter = $report->reporter;
                $reviewer = $report->reviewer;

                return [
                    'id' => $report->id,
                    'status' => $report->status,
                    'reason_category' => $report->reason_category,
                    'reason' => $report->reason,
                    'evidence_url' => $report->evidence_url,
                    'created_at' => $formatter->iso($report->created_at),
                    'reviewed_at' => $formatter->iso($report->reviewed_at),
                    'reporter' => $reporter ? [
                        'id' => $reporter->id,
                        'nickname' => $reporter->nickname,
                        'email' => $reporter->email,
                    ] : null,
                    'reviewer' => $reviewer ? [
                        'id' => $reviewer->id,
                        'nickname' => $reviewer->nickname,
                        'email' => $reviewer->email,
                    ] : null,
                    'comment' => $comment ? [
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
                    ] : null,
                    'blog' => $blog ? [
                        'id' => $blog->id,
                        'title' => $blog->title,
                        'slug' => $blog->slug,
                        'status' => $blog->status,
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
                ...$this->inertiaPagination($reports),
            ],
            'filters' => [
                'status' => $status,
                'reason_category' => $reasonCategory,
                'search' => $search,
                'per_page' => $perPage,
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
