<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\BlogCommentReaction;
use App\Support\Localization\DateFormatter;
use App\Support\Spam\CommentGuard;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BlogCommentController extends Controller
{
    public function index(Request $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = max(1, min($perPage, 50));

        $sortFilter = $request->string('sort')->lower();
        $sortMode = match ($sortFilter->value()) {
            'newest' => 'newest',
            'top' => 'top',
            default => 'oldest',
        };

        $formatter = DateFormatter::for($request->user());

        $comments = $blog->comments()
            ->with([
                'user:id,nickname,avatar_url,profile_bio',
                'reactions' => function ($query) use ($request) {
                    $userId = optional($request->user())->id ?? 0;
                    $query->where('user_id', $userId);
                },
            ])
            ->where('status', BlogComment::STATUS_APPROVED)
            ->when($sortMode === 'top', function ($query) {
                $query
                    ->orderByRaw('(like_count - dislike_count) DESC')
                    ->orderByDesc('like_count')
                    ->orderByDesc('created_at');
            }, function ($query) use ($sortMode) {
                $query->orderBy('created_at', $sortMode === 'newest' ? 'desc' : 'asc');
            })
            ->paginate($perPage);

        $items = $comments->getCollection()
            ->map(fn (BlogComment $comment) => $this->transformComment($comment, $formatter, $request))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $comments->currentPage(),
                'from' => $comments->firstItem(),
                'last_page' => max($comments->lastPage(), 1),
                'per_page' => $comments->perPage(),
                'to' => $comments->lastItem(),
                'total' => $comments->total(),
            ],
            'links' => [
                'first' => $comments->url(1),
                'last' => $comments->url(max($comments->lastPage(), 1)),
                'prev' => $comments->previousPageUrl(),
                'next' => $comments->nextPageUrl(),
            ],
        ]);
    }

    public function store(Request $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);
        abort_if(! $blog->comments_enabled, 403);

        $user = $request->user();

        abort_if($user === null, 403);

        app(CommentGuard::class)->validate($request);

        $body = $this->validatedBody($request);

        $comment = $blog->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'status' => BlogComment::STATUS_APPROVED,
        ]);

        $comment->load([
            'user:id,nickname,avatar_url,profile_bio',
            'reactions' => function ($query) use ($user) {
                $query->where('user_id', $user?->id ?? 0);
            },
        ]);

        $blog->loadMissing('user');

        $recipients = $blog->commentSubscribers()
            ->where('users.id', '!=', $user->id)
            ->get();

        if ($blog->user && $blog->user->id !== $user->id) {
            $recipients->push($blog->user);
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            $notification = new BlogCommentPosted($blog, $comment);

            $recipients->each(function (User $recipient) use ($notification): void {
                $recipient->notifyThroughPreferences($notification, 'blogs', ['database', 'mail', 'push']);
            });
        }

        $formatter = DateFormatter::for($request->user());

        return response()->json([
            'data' => $this->transformComment($comment, $formatter, $request),
        ], 201);
    }

    public function update(Request $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        $this->authorize('update', $comment);

        $body = $this->validatedBody($request);

        $comment->forceFill([
            'body' => $body,
        ])->save();

        $comment->load([
            'user:id,nickname,avatar_url,profile_bio',
            'reactions' => function ($query) use ($request) {
                $query->where('user_id', optional($request->user())->id ?? 0);
            },
        ]);

        $formatter = DateFormatter::for($request->user());

        return response()->json([
            'data' => $this->transformComment($comment, $formatter, $request),
        ]);
    }

    public function destroy(Request $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        $this->authorize('delete', $comment);

        $commentId = $comment->id;

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully.',
            'id' => $commentId,
        ]);
    }

    public function react(Request $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'reaction' => ['required', 'string', Rule::in(['like', 'dislike', 'none'])],
        ]);

        DB::transaction(function () use ($validated, $comment, $user): void {
            $current = BlogCommentReaction::query()
                ->where('blog_comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->first();

            $likeCount = (int) $comment->like_count;
            $dislikeCount = (int) $comment->dislike_count;

            if ($validated['reaction'] === 'none') {
                if ($current) {
                    if ($current->reaction === 'like') {
                        $likeCount = max(0, $likeCount - 1);
                    }

                    if ($current->reaction === 'dislike') {
                        $dislikeCount = max(0, $dislikeCount - 1);
                    }

                    $current->delete();
                }
            } elseif ($current === null) {
                BlogCommentReaction::create([
                    'blog_comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'reaction' => $validated['reaction'],
                ]);

                if ($validated['reaction'] === 'like') {
                    $likeCount++;
                } else {
                    $dislikeCount++;
                }
            } elseif ($current->reaction !== $validated['reaction']) {
                if ($current->reaction === 'like') {
                    $likeCount = max(0, $likeCount - 1);
                } else {
                    $dislikeCount = max(0, $dislikeCount - 1);
                }

                $current->forceFill(['reaction' => $validated['reaction']])->save();

                if ($validated['reaction'] === 'like') {
                    $likeCount++;
                } else {
                    $dislikeCount++;
                }
            }

            $comment->forceFill([
                'like_count' => $likeCount,
                'dislike_count' => $dislikeCount,
            ])->save();
        });

        $comment->refresh();

        $comment->load([
            'user:id,nickname,avatar_url,profile_bio',
            'reactions' => function ($query) use ($user) {
                $query->where('user_id', $user?->id ?? 0);
            },
        ]);

        $formatter = DateFormatter::for($request->user());

        return response()->json([
            'data' => $this->transformComment($comment, $formatter, $request),
        ]);
    }

    public function report(Request $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        abort_if(! $blog->comments_enabled, 403);

        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($user->can('report', $comment), 403);

        $reasons = config('forum.report_reasons', []);

        $validated = $request->validate([
            'reason_category' => ['required', 'string', Rule::in(array_keys($reasons))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'evidence_url' => ['nullable', 'string', 'max:2048', 'url'],
        ]);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        $reason = $reason === '' ? null : $reason;

        $evidenceUrl = isset($validated['evidence_url']) ? trim((string) $validated['evidence_url']) : null;
        $evidenceUrl = $evidenceUrl === '' ? null : $evidenceUrl;

        BlogCommentReport::updateOrCreate(
            [
                'blog_comment_id' => $comment->id,
                'reporter_id' => $user->id,
            ],
            [
                'reason_category' => $validated['reason_category'],
                'reason' => $reason,
                'evidence_url' => $evidenceUrl,
                'status' => BlogCommentReport::STATUS_PENDING,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ],
        );

        if (! $comment->is_flagged) {
            $comment->forceFill(['is_flagged' => true])->save();
        }

        return response()->json([
            'message' => 'Report submitted to the moderation team.',
        ], 201);
    }

    private function validatedBody(Request $request): string
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'captcha_token' => ['required', 'string'],
            'honeypot' => ['nullable', 'string', 'max:0'],
        ]);

        $body = trim($validated['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Comment cannot be empty.',
            ]);
        }

        return $body;
    }

    private function ensureCommentBelongsToBlog(Blog $blog, BlogComment $comment): void
    {
        abort_if($comment->blog_id !== $blog->id, 404);
        abort_unless($blog->status === 'published', 404);
    }

    private function transformComment(BlogComment $comment, DateFormatter $formatter, Request $request): array
    {
        $comment->loadMissing(['user:id,nickname,avatar_url,profile_bio']);

        $user = $comment->user;

        $avatarUrl = null;
        $profileBio = null;

        if ($user) {
            $avatarCandidate = is_string($user->avatar_url) ? trim($user->avatar_url) : '';
            $avatarUrl = $avatarCandidate !== '' ? $avatarCandidate : null;

            $bioCandidate = is_string($user->profile_bio) ? trim($user->profile_bio) : '';
            $profileBio = $bioCandidate !== '' ? $bioCandidate : null;
        }

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $formatter->iso($comment->created_at),
            'updated_at' => $formatter->iso($comment->updated_at),
            'permissions' => [
                'can_update' => $request->user()?->can('update', $comment) ?? false,
                'can_delete' => $request->user()?->can('delete', $comment) ?? false,
                'can_report' => $request->user()?->can('report', $comment) ?? false,
            ],
            'reactions' => [
                'likes' => (int) $comment->like_count,
                'dislikes' => (int) $comment->dislike_count,
                'user_reaction' => optional($comment->reactions->first())->reaction,
            ],
            'user' => $user ? [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'avatar_url' => $avatarUrl,
                'profile_bio' => $profileBio,
            ] : null,
        ];
    }
}
