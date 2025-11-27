<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Support\Localization\DateFormatter;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BlogCommentController extends Controller
{
    public function index(Request $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = max(1, min($perPage, 50));

        $formatter = DateFormatter::for($request->user());

        $comments = $blog->comments()
            ->with(['user:id,nickname,avatar_url,profile_bio'])
            ->where('status', BlogComment::STATUS_APPROVED)
            ->orderBy('created_at')
            ->paginate($perPage);

        $items = $comments->getCollection()
            ->map(fn (BlogComment $comment) => $this->transformComment($comment, $formatter))
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

        $user = $request->user();

        abort_if($user === null, 403);

        $body = $this->validatedBody($request);

        $comment = $blog->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'status' => BlogComment::STATUS_APPROVED,
        ]);

        $comment->load(['user:id,nickname,avatar_url,profile_bio']);

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
            'data' => $this->transformComment($comment, $formatter),
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

        $comment->load(['user:id,nickname,avatar_url,profile_bio']);

        $formatter = DateFormatter::for($request->user());

        return response()->json([
            'data' => $this->transformComment($comment, $formatter),
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

    private function validatedBody(Request $request): string
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
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

    private function transformComment(BlogComment $comment, DateFormatter $formatter): array
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
            'user' => $user ? [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'avatar_url' => $avatarUrl,
                'profile_bio' => $profileBio,
            ] : null,
        ];
    }
}
