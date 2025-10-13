<?php

use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    if ($user->getKey() !== $id) {
        return false;
    }

    return [
        'id' => $user->id,
        'nickname' => $user->nickname,
        'avatar_url' => $user->avatar_url,
    ];
});

Broadcast::channel('forum.threads.{threadId}', function (User $user, int $threadId) {
    $thread = ForumThread::query()
        ->select(['id', 'forum_board_id', 'user_id', 'is_published'])
        ->find($threadId);

    if (! $thread) {
        return false;
    }

    $isModerator = $user->hasAnyRole(['admin', 'editor', 'moderator']);
    $isAuthor = $thread->user_id === $user->id;

    if (! $thread->is_published && ! $isModerator && ! $isAuthor) {
        return false;
    }

    return [
        'id' => $user->id,
        'nickname' => $user->nickname,
        'avatar_url' => $user->avatar_url,
    ];
});
