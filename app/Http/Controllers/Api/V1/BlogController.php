<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BlogResource;
use App\Models\Blog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $blogs = Blog::query()
            ->with(['user', 'categories', 'tags'])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->paginate();

        return BlogResource::collection($blogs);
    }

    public function show(Blog $blog): BlogResource
    {
        $blog->loadMissing(['user', 'categories', 'tags']);

        return new BlogResource($blog);
    }
}
