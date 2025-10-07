<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumPostRevision;
use App\Models\ForumThread;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ForumPostRevisionController extends Controller
{
    public function index(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post): Response
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();
        abort_if($user === null, 403);

        Gate::authorize('viewHistory', $post);

        $board->loadMissing('category:id,title,slug');
        $post->loadMissing('author:id,nickname');
        $thread->loadMissing('board:id,title,slug', 'board.category:id,title,slug');

        $formatter = DateFormatter::for($request->user());

        $revisions = $post->revisions()
            ->with('editor:id,nickname')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ForumPostRevision $revision) use ($formatter) {
                return [
                    'id' => $revision->id,
                    'body' => $revision->body,
                    'edited_at' => $formatter->iso($revision->edited_at),
                    'created_at' => $formatter->iso($revision->created_at),
                    'editor' => $revision->editor?->only(['id', 'nickname']),
                ];
            })
            ->values();

        return Inertia::render('acp/ForumPostHistory', [
            'board' => [
                'id' => $board->id,
                'title' => $board->title,
                'slug' => $board->slug,
                'category' => [
                    'title' => $board->category?->title,
                    'slug' => $board->category?->slug,
                ],
            ],
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
            ],
            'post' => [
                'id' => $post->id,
                'body' => $post->body,
                'created_at' => $formatter->iso($post->created_at),
                'edited_at' => $formatter->iso($post->edited_at),
                'author' => $post->author?->only(['id', 'nickname']),
                'permissions' => [
                    'canRestore' => Gate::allows('restoreRevision', $post),
                ],
            ],
            'revisions' => $revisions,
        ]);
    }

    public function restore(Request $request, ForumBoard $board, ForumThread $thread, ForumPost $post, ForumPostRevision $revision): RedirectResponse
    {
        $this->ensureHierarchy($board, $thread, $post);

        abort_if($revision->forum_post_id !== $post->id, 404);

        $user = $request->user();
        abort_if($user === null, 403);

        Gate::authorize('restoreRevision', $post);

        $post->revisions()->create([
            'body' => $post->body,
            'edited_at' => $post->edited_at,
            'edited_by_id' => $user->id,
        ]);

        $post->forceFill([
            'body' => $revision->body,
            'edited_at' => Carbon::now(),
        ])->save();

        return redirect()
            ->route('forum.posts.history', [$board, $thread, $post])
            ->with('success', 'Revision restored successfully.');
    }

    private function ensureHierarchy(ForumBoard $board, ForumThread $thread, ForumPost $post): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
        abort_if($post->forum_thread_id !== $thread->id, 404);
    }
}
