<?php

/*
 * The public member directory and profiles. Sent to the browser (config/i18n.php).
 * Read them with t('members.directory.title').
 */

return [
    'directory' => [
        'title' => 'Members',
        'meta' => 'The people who make up this community.',
        'description' => 'Find someone by name and open their profile.',
        'search_label' => 'Search members by name',
        'search_placeholder' => 'Search by name',
        'submit' => 'Search',
        'sort_label' => 'Sort members',
        'sort_newest' => 'Newest',
        'sort_name' => 'Name',
        'sort_reputation' => 'Reputation',
        'empty' => 'No members to show.',
        'empty_search' => 'No members match that name.',
        'joined' => 'Joined :date',
        'reputation' => ':count reputation',
        'previous' => 'Previous page',
        'next' => 'Next page',
        'page' => 'Page :current of :last',
    ],

    'profile' => [
        'meta' => ':name on this community.',
        'member_since' => 'Member since :date',
        'role_member' => 'Member',
        'reputation' => 'Reputation',
        'about' => 'About',
        'links' => 'Links',
        'signature' => 'Forum signature',
        'threads' => 'Threads',
        'replies' => 'Replies',
        'comments' => 'Blog comments',
        'recent_threads' => 'Recent threads',
        'recent_replies' => 'Recent replies',
        'no_threads' => 'No public threads yet.',
        'no_replies' => 'No public replies yet.',
        'view_public' => 'View public profile',
        'open_profile' => 'View :name\'s profile',
        'ignore' => 'Ignore',
        'stop_ignoring' => 'Stop ignoring',
    ],
];
