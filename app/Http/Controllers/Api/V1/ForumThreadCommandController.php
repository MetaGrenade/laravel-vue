<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forum\StoreForumThreadRequest;
use App\Http\Requests\Api\V1\Forum\UpdateForumThreadRequest;
use App\Http\Resources\Api\V1\ForumPostResource;
use App\Http\Resources\Api\V1\ForumThreadResource;
use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Support\Database\Transaction;
use App\Support\Reputation\ReputationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForumThreadCommandController extends Controller
{
    public function __construct(private readonly ReputationManager $reputation)
    {
    }

    public function store(StoreForumThreadRequest $request, ForumBoard $board): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $validated = $request->validated();

        $title = trim((string) $validated['title']);
        $body = trim((string) $validated['body']);
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter some content before publishing.',
            ]);
        }

        $baseSlug = Str::slug($title);
        $baseSlug = $baseSlug === '' ? 'thread' : Str::limit($baseSlug, 240, '');

        do {
            $slug = $baseSlug . '-' . Str::random(6);
        } while (ForumThread::where('slug', $slug)->exists());

        $thread = null;
        $initialPost = null;

        Transaction::run(function () use ($board, $user, $title, $slug, $body, $bodyText, &$thread, &$initialPost) {
            $excerptSource = $bodyText;

            $thread = ForumThread::create([
                'forum_board_id' => $board->id,
                'user_id' => $user->id,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => Str::limit($excerptSource, 160),
                'last_posted_at' => now(),
                'last_post_user_id' => $user->id,
            ]);

            $initialPost = ForumPost::create([
                'forum_thread_id' => $thread->id,
                'user_id' => $user->id,
                'body' => $body,
            ]);

            $thread->forceFill([
                'last_posted_at' => $initialPost->created_at,
                'last_post_user_id' => $initialPost->user_id,
            ])->save();
        });

        if ($initialPost !== null) {
            ForumThreadRead::updateOrCreate(
                [
                    'forum_thread_id' => $thread->id,
                    'user_id' => $user->id,
                ],
                [
                    'last_read_post_id' => $initialPost->id,
                    'last_read_at' => $initialPost->created_at ?? now(),
                ],
            );

            $this->reputation->record('forum_post_created', $user, $initialPost, [
                'thread_id' => $thread->id,
            ]);
        }

        $thread->loadMissing(['board', 'author', 'latestPost.author']);
        $initialPost?->loadMissing('author');

        return response()->json([
            'thread' => new ForumThreadResource($thread),
            'initial_post' => $initialPost ? new ForumPostResource($initialPost) : null,
        ], 201);
    }

    public function update(UpdateForumThreadRequest $request, ForumBoard $board, ForumThread $thread): ForumThreadResource
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = $request->user();

        abort_if($user === null, 401);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
        $canEditAsAuthor = $user->id === $thread->user_id && $thread->is_published && ! $thread->is_locked;

        abort_unless($isModerator || $canEditAsAuthor, 403);

        $validated = $request->validated();
        $title = trim((string) $validated['title']);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'The thread title cannot be empty.',
            ]);
        }

        if ($title !== $thread->title) {
            $thread->forceFill([
                'title' => $title,
            ])->save();
        }

        $thread->loadMissing(['board', 'author', 'latestPost.author']);

        return new ForumThreadResource($thread);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }
}
