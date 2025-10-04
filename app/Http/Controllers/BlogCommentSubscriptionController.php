<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogCommentSubscriptionController extends Controller
{
    public function store(Request $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $blog->commentSubscribers()->syncWithoutDetaching([$user->id]);
        $blog->loadCount('commentSubscribers');

        return response()->json([
            'subscribed' => true,
            'subscribers_count' => $blog->comment_subscribers_count,
        ]);
    }

    public function destroy(Request $request, Blog $blog): JsonResponse
    {
        abort_unless($blog->status === 'published', 404);

        $user = $request->user();

        abort_if($user === null, 403);

        $blog->commentSubscribers()->detach($user->id);
        $blog->loadCount('commentSubscribers');

        return response()->json([
            'subscribed' => false,
            'subscribers_count' => $blog->comment_subscribers_count,
        ]);
    }
}
