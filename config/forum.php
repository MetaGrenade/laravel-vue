<?php

return [
    'index_cache_ttl' => env('FORUM_INDEX_CACHE_TTL', 300),

    'report_reasons' => [
        'spam' => [
            'label' => 'Spam or advertising',
            'description' => 'Unwanted promotional content, phishing attempts, or repetitive messages posted across threads.',
        ],
        'abuse' => [
            'label' => 'Harassment or hate',
            'description' => 'Content that targets an individual or group with abusive, harassing, or hateful language and behavior.',
        ],
        'illegal' => [
            'label' => 'Illegal or dangerous content',
            'description' => 'Discussions that promote illegal activity, self-harm, or other dangerous behavior.',
        ],
        'nsfw' => [
            'label' => 'Adult or NSFW material',
            'description' => 'Explicit sexual content or other material that should not appear in the public forum.',
        ],
        'misinformation' => [
            'label' => 'Misinformation',
            'description' => 'False or misleading information that could negatively impact the community if left unchecked.',
        ],
        'other' => [
            'label' => 'Other rule violation',
            'description' => 'Any other issue that violates the forum guidelines and needs moderator attention.',
        ],
    ],
];
