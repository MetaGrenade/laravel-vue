<?php

return [
    'attachments' => [
        // Private disk: attachments must not be served directly by the web server.
        'disk' => env('SUPPORT_ATTACHMENT_DISK', 'local'),
        'directory' => 'support-attachments',
    ],

    'sla' => [
        'priority_escalations' => [
            'low' => [
                'after' => '48 hours',
                'to' => 'medium',
            ],
            'medium' => [
                'after' => '24 hours',
                'to' => 'high',
            ],
        ],
        'reassign_after' => [
            'low' => '72 hours',
            'medium' => '36 hours',
            'high' => '12 hours',
        ],
    ],
];
