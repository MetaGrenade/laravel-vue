<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forum\StoreForumThreadRequest;
use App\Http\Requests\Api\V1\Forum\UpdateForumThreadRequest;
use App\Http\Resources\Api\V1\ForumPostResource;
use App\Http\Resources\Api\V1\ForumThreadResource;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Support\Forum\ForumSubmissions;
use App\Support\Forum\ThreadPrefixes;
use App\Support\Security\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ForumThreadCommandController extends Controller
{
    public function store(StoreForumThreadRequest $request, ForumBoard $board, ForumSubmissions $submissions, ThreadPrefixes $prefixes): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $validated = $request->validated();

        $title = trim((string) $validated['title']);
        $body = trim(app(HtmlSanitizer::class)->forum((string) $validated['body']));
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter some content before publishing.',
            ]);
        }

        $prefix = $prefixes->resolveSlug($board, $validated['prefix'] ?? null, (bool) $board->prefixes_required);

        $submission = $submissions->thread($user, $board, $title, $body, $prefix);

        $thread = $submission->thread;
        $initialPost = $submission->post;

        $thread->loadMissing(['board', 'author', 'prefix', 'latestPost.author']);
        $initialPost->loadMissing('author');

        // 202 when it was received but a rule has held it for a moderator (docs/automod.md): it is not out in the open yet.
        return response()->json([
            'thread' => new ForumThreadResource($thread),
            'initial_post' => new ForumPostResource($initialPost),
        ], $submission->held ? 202 : 201);
    }

    public function update(UpdateForumThreadRequest $request, ForumBoard $board, ForumThread $thread, ThreadPrefixes $prefixes): ForumThreadResource
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

        $changes = [];

        if ($title !== $thread->title) {
            $changes['title'] = $title;
        }

        if (array_key_exists('prefix', $validated)) {
            $prefix = $prefixes->resolveSlug($board, $validated['prefix'], false);
            $prefixId = $prefix?->id;

            if ($prefixId !== $thread->forum_prefix_id) {
                $changes['forum_prefix_id'] = $prefixId;
            }
        }

        if ($changes !== []) {
            $thread->forceFill($changes)->save();
        }

        $thread->loadMissing(['board', 'author', 'prefix', 'latestPost.author']);

        return new ForumThreadResource($thread);
    }

    protected function ensureThreadBelongsToBoard(ForumBoard $board, ForumThread $thread): void
    {
        abort_if($thread->forum_board_id !== $board->id, 404);
    }
}
