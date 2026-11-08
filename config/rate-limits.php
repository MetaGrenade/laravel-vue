<?php

return [
    'blog_comments_per_minute' => env('BLOG_COMMENT_RATE_LIMIT', 5),

    // How many calls a minute one account may make to each part of the API (docs/api.md, "Limits"). Each is a count of its own: a call
    // to the polls does not use up what the forum has left, and a route that lists two of them counts against both of them once.
    // They are numbers here and not .env settings; tests set them lower to reach them quickly. `support` is every call to the support
    // routes together, and the three after it narrow it for one kind of call (a read is allowed 30, but the 20 above comes first).
    'api' => [
        'blog' => 20,
        'polls' => 20,
        'forum_write' => 30,
        'forum_moderate' => 20,
        'support' => 20,
        'support_write' => 10,
        'support_read' => 30,
        'support_rating' => 5,
    ],
];
