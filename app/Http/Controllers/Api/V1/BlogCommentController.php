<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Blog\ReportBlogCommentRequest;
use App\Http\Requests\Api\V1\Blog\StoreBlogCommentRequest;
use App\Http\Requests\Api\V1\Blog\UpdateBlogCommentRequest;
use App\Http\Resources\Api\V1\BlogCommentResource;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\BlogCommentReaction;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BlogCommentController extends Controller
{
    public function index(Request $request, Blog $blog): AnonymousResourceCollection
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

        $comments = $blog->comments()
            ->with([
                'user',
                'reactions' => function ($query) use ($request) {
                    $query->where('user_id', optional($request->user())->id ?? 0);
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

        return BlogCommentResource::collection($comments);
    }

    public function store(StoreBlogCommentRequest $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);
        abort_if(! $blog->comments_enabled, 403);

        $user = $request->user();
        abort_if($user === null, 401);

        $body = $this->validatedBody($request->validated());

        $comment = $blog->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'status' => BlogComment::STATUS_APPROVED,
        ]);

        $comment->load([
            'user',
            'reactions' => function ($query) use ($request) {
                $query->where('user_id', optional($request->user())->id ?? 0);
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

        return response()->json(new BlogCommentResource($comment), 201);
    }

    public function update(UpdateBlogCommentRequest $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        $this->authorize('update', $comment);

        $body = $this->validatedBody($request->validated());

        $comment->forceFill([
            'body' => $body,
        ])->save();

        $comment->load([
            'user',
            'reactions' => function ($query) use ($request) {
                $query->where('user_id', optional($request->user())->id ?? 0);
            },
        ]);

        return response()->json(new BlogCommentResource($comment));
    }

    public function destroy(Blog $blog, BlogComment $comment): JsonResponse
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
        abort_if($user === null, 401);

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
            'user',
            'reactions' => function ($query) use ($request) {
                $query->where('user_id', optional($request->user())->id ?? 0);
            },
        ]);

        return response()->json(new BlogCommentResource($comment));
    }

    public function report(ReportBlogCommentRequest $request, Blog $blog, BlogComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBlog($blog, $comment);

        abort_if(! $blog->comments_enabled, 403);

        $user = $request->user();
        abort_if($user === null, 401);

        abort_unless($user->can('report', $comment), 403);
        abort_unless($user->hasVerifiedEmail(), 403);

        $validated = $request->validated();

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

    private function validatedBody(array $validated): string
    {
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
}
