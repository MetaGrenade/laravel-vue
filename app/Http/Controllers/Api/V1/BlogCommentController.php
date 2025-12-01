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
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BlogCommentController extends Controller
{
    public function index(Request $request, Blog $blog): AnonymousResourceCollection
    {
        abort_unless($blog->status === 'published', 404);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = max(1, min($perPage, 50));

        $comments = $blog->comments()
            ->with(['user'])
            ->where('status', BlogComment::STATUS_APPROVED)
            ->orderBy('created_at')
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

        $comment->load(['user']);

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

        $comment->load(['user']);

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
