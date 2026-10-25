<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discord API
    |--------------------------------------------------------------------------
    */

    'bot_token' => env('DISCORD_BOT_TOKEN'),

    'guild_id' => env('DISCORD_GUILD_ID'),

    'application_id' => env('DISCORD_APPLICATION_ID'),

    'api_base' => rtrim(env('DISCORD_API_BASE', 'https://discord.com/api/v10'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Bot ↔ Laravel authentication
    |--------------------------------------------------------------------------
    */

    'bot_shared_secret' => env('DISCORD_BOT_SHARED_SECRET'),

    'webhook_max_skew_seconds' => (int) env('DISCORD_WEBHOOK_MAX_SKEW', 300),

    /*
    |--------------------------------------------------------------------------
    | Role sync (Spatie → Discord)
    |--------------------------------------------------------------------------
    */

    'role_sync_enabled' => (bool) env('DISCORD_ROLE_SYNC_ENABLED', true),

    'preserve_unmapped_roles' => (bool) env('DISCORD_PRESERVE_UNMAPPED_ROLES', true),

    'stale_sync_hours' => (int) env('DISCORD_STALE_SYNC_HOURS', 24),

    'schedule_stale_sync' => (bool) env('DISCORD_SCHEDULE_STALE_SYNC', true),

    'social_provider' => env('DISCORD_SOCIAL_PROVIDER', 'discord'),

    /*
    |--------------------------------------------------------------------------
    | Default notification webhook (optional)
    |--------------------------------------------------------------------------
    */

    'default_webhook_url' => env('DISCORD_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Notification opt-in categories (user settings + DiscordNotifiableNotification)
    |--------------------------------------------------------------------------
    */

    'notification_categories' => [
        'support_ticket_reply' => 'Support ticket replies',
        'order_shipped' => 'Order shipped updates',
        'account_moderation' => 'Account moderation notices',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP routes (middleware applied in host app context)
    |--------------------------------------------------------------------------
    */

    'admin_route_prefix' => env('DISCORD_ADMIN_ROUTE_PREFIX', 'discord/admin'),

    'admin_middleware' => ['web', 'auth', 'verified', 'role:admin|editor|moderator', 'can:system.acp.view'],

    'user_route_prefix' => env('DISCORD_USER_ROUTE_PREFIX', 'discord/user'),

    'user_middleware' => ['web', 'auth', 'verified'],

    /*
    |--------------------------------------------------------------------------
    | Models (override in host app if needed)
    |--------------------------------------------------------------------------
    */

    'user_model' => env('DISCORD_USER_MODEL', 'App\\Models\\User'),

    'social_account_model' => env('DISCORD_SOCIAL_ACCOUNT_MODEL', 'App\\Models\\SocialAccount'),

];
