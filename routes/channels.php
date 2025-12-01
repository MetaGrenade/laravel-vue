<?php

use App\Models\ForumThread;
use App\Models\SupportTicket;
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

Broadcast::channel('support.tickets.{ticketId}', function (User $user, int $ticketId) {
    $ticket = SupportTicket::query()
        ->select(['id', 'user_id', 'assigned_to', 'support_team_id'])
        ->with([
            'assignee:id,nickname,avatar_url',
            'assignee.supportTeams.members:id',
            'team.members:id',
        ])
        ->find($ticketId);

    if (! $ticket) {
        return false;
    }

    $isOwner = (int) $ticket->user_id === (int) $user->id;
    $isAssignee = (int) $ticket->assigned_to === (int) $user->id;
    $isTeamMember = (bool) $ticket->team?->members->contains(fn (User $member) => $member->id === $user->id);

    $assigneeTeamMember = (bool) optional($ticket->assignee)
        ->supportTeams
        ->flatMap(fn ($team) => $team->members)
        ->contains(fn (User $member) => $member->id === $user->id);

    if (! ($isOwner || $isAssignee || $isTeamMember || $assigneeTeamMember || $user->can('support.acp.view'))) {
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
