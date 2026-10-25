<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forum\StoreForumPostRequest;
use App\Http\Requests\Api\V1\Forum\UpdateForumPostRequest;
use App\Http\Resources\Api\V1\ForumPostResource;
use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\ForumPublisher;
use App\Support\Forum\ForumSubmissions;
use App\Support\Security\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ForumPostCommandController extends Controller
{
    public function __construct(private readonly ForumPublisher $publisher) {}

    public function store(StoreForumPostRequest $request, ForumBoard $board, ForumThread $thread, ForumSubmissions $submissions): JsonResponse
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        $user = $request->user();

        abort_if($user === null, 401);
        abort_if($thread->is_locked || ! $thread->is_published, 403);

        $validated = $request->validated();

        $body = trim(app(HtmlSanitizer::class)->forum((string) $validated['body']));
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Reply cannot be empty.',
            ]);
        }

        $submission = $submissions->reply($user, $thread, $body);

        $post = $submission->post;
        $post->loadMissing('author');

        // 202 when it was received but a rule has held it for a moderator (docs/automod.md): it is not out in the open yet.
        return response()->json(new ForumPostResource($post), $submission->held ? 202 : 201);
    }

    public function update(UpdateForumPostRequest $request, ForumBoard $board, ForumThread $thread, ForumPost $post): ForumPostResource
    {
        $this->ensureHierarchy($board, $thread, $post);

        $user = $request->user();

        abort_if($user === null, 401);

        $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
        $canEditAsAuthor = $user->id === $post->user_id && $thread->is_published && ! $thread->is_locked;

        abort_unless($isModerator || $canEditAsAuthor, 403);

        $validated = $request->validated();

        $body = trim(app(HtmlSanitizer::class)->forum((string) $validated['body']));
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Post content cannot be empty.',
            ]);
        }

        $previousMentionIds = $post->mentions()->pluck('users.id');

        if ($body !== $post->body) {
            $post->revisions()->create([
                'body' => $post->body,
                'edited_at' => $post->edited_at,
                'edited_by_id' => $user->id,
            ]);
        }

        $post->forceFill([
            'body' => $body,
            'edited_at' => Carbon::now(),
        ])->save();

        $mentionedUsers = $this->publisher->resolveMentionedUsers($body, $user->id);

        $post->mentions()->sync($mentionedUsers->pluck('id')->all());

        $newlyMentionedUsers = $mentionedUsers->filter(fn (User $mentioned) => ! $previousMentionIds->contains($mentioned->id));

        if ($newlyMentionedUsers->isNotEmpty()) {
            $thread->loadMissing('board');
            $this->publisher->notifyMentionedUsers($newlyMentionedUsers, $thread, $post);
        }

        $post->loadMissing('author');

        return new ForumPostResource($post);
    }

    protected function ensureHierarchy(ForumBoard $board, ForumThread $thread, ForumPost $post): void
    {
        $this->ensureThreadBelongsToBoard($board, $thread);

        abort_if($post->forum_thread_id !== $thread->id, 404);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }
}
