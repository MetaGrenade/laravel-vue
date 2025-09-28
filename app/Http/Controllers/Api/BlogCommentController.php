<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlogCommentRequest;
use App\Http\Resources\BlogCommentResource;
use App\Models\Blog;
use Symfony\Component\HttpFoundation\Response;

class BlogCommentController extends Controller
{
    public function index(Blog $blog)
    {
        abort_unless($blog->status === 'published', Response::HTTP_NOT_FOUND);

        $comments = $blog->comments()
            ->with(['user:id,nickname'])
            ->orderBy('created_at')
            ->paginate(20);

        return BlogCommentResource::collection($comments);
    }

    public function store(StoreBlogCommentRequest $request, Blog $blog)
    {
        abort_unless($blog->status === 'published', Response::HTTP_NOT_FOUND);

        $comment = $blog->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->input('body'),
        ]);

        $comment->load(['user:id,nickname']);

        return BlogCommentResource::make($comment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
