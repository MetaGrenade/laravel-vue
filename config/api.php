<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API key scopes
    |--------------------------------------------------------------------------
    |
    | What a key can be limited to. A key holds one or more of these (or `*`, every scope, which only staff can give), and each
    | authenticated route under /api asks for the one it needs with the `scope:` middleware (routes/api.php). A key can never do more
    | than the person it belongs to: a scope narrows, it does not add. See docs/api-keys.md.
    |
    | `roles` keeps a scope to people who hold one of those roles, so a member is not offered something that could never work.
    |
    */

    'scopes' => [
        'profile:read' => [
            'label' => 'Read your profile',
            'description' => 'Your nickname, email address and account details.',
        ],
        'blog:write' => [
            'label' => 'Comment on blog posts',
            'description' => 'Write, edit and delete your comments, react to comments, report them, and follow a post\'s comments.',
        ],
        'polls:vote' => [
            'label' => 'Vote in polls',
            'description' => 'Cast your vote in a poll.',
        ],
        'forum:write' => [
            'label' => 'Post in the forum',
            'description' => 'Start threads, reply, edit your posts, and follow threads.',
        ],
        'forum:moderate' => [
            'label' => 'Moderate the forum',
            'description' => 'Publish, lock, pin and delete threads.',
            'roles' => ['admin', 'editor', 'moderator'],
        ],
        'support:read' => [
            'label' => 'Read your support tickets',
            'description' => 'Open your tickets and download their attachments.',
        ],
        'support:write' => [
            'label' => 'Write to support',
            'description' => 'Open tickets, reply, change a ticket\'s status, reopen it, and rate the help you got.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keys people make for themselves
    |--------------------------------------------------------------------------
    |
    | Settings → API keys. `max_active` is how many keys that can still be used one person may have at a time (revoked and expired keys
    | do not count). `expiry_days` are the lifetimes offered, `default_expiry_days` is the one chosen to begin with, and a key that never
    | expires is offered only while `allow_no_expiry` is on. Staff can make keys of any lifetime under Admin → Tokens.
    |
    */

    'keys' => [
        'max_active' => (int) env('API_KEYS_MAX', 10),
        'expiry_days' => [30, 90, 365],
        'default_expiry_days' => 90,
        'allow_no_expiry' => (bool) env('API_KEYS_ALLOW_NO_EXPIRY', true),
    ],

];
