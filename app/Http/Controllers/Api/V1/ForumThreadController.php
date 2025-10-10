<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ForumThreadResource;
use App\Models\ForumThread;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ForumThreadController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $threads = ForumThread::query()
            ->with(['board', 'author', 'latestPost.author'])
            ->where('is_published', true)
            ->orderByDesc('last_posted_at')
            ->paginate();

        return ForumThreadResource::collection($threads);
    }

    public function show(ForumThread $thread): ForumThreadResource
    {
        $thread->loadMissing(['board', 'author', 'latestPost.author']);

        return new ForumThreadResource($thread);
    }
}
