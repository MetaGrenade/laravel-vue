<?php

use App\Models\ForumThread;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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

Broadcast::channel('support.tickets.{ticketId}', function (User $user, int $ticketId) {
    $ticket = SupportTicket::query()
        ->select(['id', 'user_id'])
        ->find($ticketId);

    if (! $ticket) {
        return false;
    }

    $isOwner = (int) $ticket->user_id === (int) $user->id;

    $canViewSupport = false;

    try {
        if (method_exists($user, 'hasPermissionTo')) {
            $canViewSupport = $user->hasPermissionTo('support.acp.view');
        }
    } catch (PermissionDoesNotExist $exception) {
        $canViewSupport = false;
    }

    if (! $canViewSupport && (! method_exists($user, 'hasPermissionTo') || Gate::has('support.acp.view'))) {
        $canViewSupport = $user->can('support.acp.view');
    }

    if (! $isOwner && ! $canViewSupport) {
        return false;
    }

    return [
        'id' => $user->id,
        'nickname' => $user->nickname,
        'avatar_url' => $user->avatar_url,
    ];
});
