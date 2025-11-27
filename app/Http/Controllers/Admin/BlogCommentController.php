<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\BlogComment;
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
            'status' => ['nullable', 'string', Rule::in(array_merge(['all'], BlogComment::STATUSES))],
            'flagged' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'all';
        $flagged = $request->has('flagged') ? $request->boolean('flagged') : null;
        $userId = $validated['user_id'] ?? null;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $search = $search === '' ? null : $search;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $perPage = max(5, min(100, $perPage));

        $query = BlogComment::query()
            ->with([
                'user:id,nickname,email,is_banned',
                'blog:id,title,slug,status',
            ])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($flagged !== null) {
            $query->where('is_flagged', $flagged);
        }

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($search !== null) {
            $query->where('body', 'like', "%{$search}%");
        }

        $comments = $query->paginate($perPage)->withQueryString();

        $formatter = DateFormatter::for($request->user());

        $items = $comments->getCollection()
            ->map(function (BlogComment $comment) use ($request, $formatter) {
                $user = $comment->user;
                $blog = $comment->blog;

                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'status' => $comment->status,
                    'is_flagged' => (bool) $comment->is_flagged,
                    'created_at' => $formatter->iso($comment->created_at),
                    'updated_at' => $formatter->iso($comment->updated_at),
                    'body_preview' => Str::limit(strip_tags($comment->body), 140),
                    'user' => $user ? [
                        'id' => $user->id,
                        'nickname' => $user->nickname,
                        'email' => $user->email,
                        'is_banned' => (bool) $user->is_banned,
                    ] : null,
                    'blog' => $blog ? [
                        'id' => $blog->id,
                        'title' => $blog->title,
                        'slug' => $blog->slug,
                        'status' => $blog->status,
                    ] : null,
                    'can' => [
                        'update' => $request->user()?->can('update', $comment) ?? false,
                        'review' => $request->user()?->can('review', $comment) ?? false,
                        'delete' => $request->user()?->can('delete', $comment) ?? false,
                    ],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('acp/BlogComments', [
            'comments' => [
                'data' => $items,
                ...$this->inertiaPagination($comments),
            ],
            'filters' => [
                'status' => $status,
                'flagged' => $flagged,
                'user_id' => $userId,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'statuses' => BlogComment::STATUSES,
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
}
