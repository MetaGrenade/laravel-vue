<?php

return [
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    'default_log_name' => 'default',

    'table_name' => 'activity_log',

    'displayable_events' => [
        'auth.login' => 'User login',
        'auth.logout' => 'User logout',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.deleted' => 'User deleted',
        'user.verified' => 'User verified',
        'user.roles.updated' => 'Role change',
        'user.banned' => 'User banned',
        'user.unbanned' => 'User unbanned',
        'user.bulk_action' => 'Bulk user update',
        'forum.thread.published' => 'Thread published',
        'forum.thread.unpublished' => 'Thread unpublished',
        'forum.thread.locked' => 'Thread locked',
        'forum.thread.unlocked' => 'Thread unlocked',
        'forum.thread.pinned' => 'Thread pinned',
        'forum.thread.unpinned' => 'Thread unpinned',
        'forum.thread.deleted' => 'Thread deleted',
        'forum.thread.title_updated' => 'Thread title updated',
        'forum.post.updated' => 'Post updated',
        'forum.post.deleted' => 'Post deleted',
        'billing.invoice.paid' => 'Invoice paid',
        'billing.invoice.failed' => 'Invoice payment failed',
        'billing.subscription.canceled' => 'Subscription canceled',
        'support.ticket.updated' => 'Ticket updated',
        'support.ticket.assigned' => 'Ticket assigned',
        'support.ticket.priority_updated' => 'Ticket priority updated',
        'support.ticket.deleted' => 'Ticket deleted',
        'support.ticket.message_added' => 'Ticket reply added',
        'support.ticket.status_updated' => 'Ticket status updated',
        'support.ticket.status_bulk_updated' => 'Ticket statuses bulk updated',
    ],
];
