<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlogCommentRequest;
use App\Models\Blog;
use Illuminate\Http\RedirectResponse;

class BlogCommentController extends Controller
{
    public function store(StoreBlogCommentRequest $request, Blog $blog): RedirectResponse
    {
        abort_unless($blog->status === 'published', 404);

        $comment = $blog->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->input('body'),
        ]);

        $perPage = max(BlogController::COMMENTS_PER_PAGE, 1);
        $total = $blog->comments()->count();
        $lastPage = (int) ceil($total / $perPage);

        $parameters = ['blog' => $blog->slug];

        if ($lastPage > 1) {
            $parameters['page'] = $lastPage;
        }

        return redirect()
            ->route('blogs.view', $parameters)
            ->withFragment('comment-' . $comment->id)
            ->with('success', 'Comment posted successfully.');
    }
}
