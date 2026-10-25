# Discord companion integration

MetaForge ships the [`metagrenade/metaforge-discord`](https://github.com/MetaGrenade/MetaForge-DiscordBot/tree/main/laravel) package for role sync, bot webhooks, and Discord notification opt-in.

## Install

The package lives in the MetaForge-DiscordBot monorepo (`laravel/`). Because there is no root `composer.json` on that repository, this app registers an inline Composer `package` repository (see root `composer.json`) that clones `main` and autoloads `laravel/src/`.

```bash
composer install
php artisan vendor:publish --tag=metaforge-discord-config
php artisan migrate
php artisan discord:ping
```

For local package development, swap the inline repository for a path repo pointing at a checkout of `MetaForge-DiscordBot/laravel`.

## Environment

Set bot and guild credentials (see `.env.example`). Users must link Discord via existing OAuth (`social_accounts` with `provider = discord`).

Run the gateway bot from [MetaForge-DiscordBot/bot](https://github.com/MetaGrenade/MetaForge-DiscordBot/tree/main/bot) with `METAFORGE_WEBHOOK_URL` set to your app URL and the same shared secret as `DISCORD_BOT_SHARED_SECRET`.

## UI

| Area | Route |
|------|--------|
| ACP role mappings | `/acp/discord/role-mappings` |
| ACP sync status / manual sync | `/acp/discord/sync` |
| User Discord notification opt-in | `/settings/discord-notifications` |

JSON APIs are provided by the package under `/discord/admin/*` and `/discord/user/notification-preferences`, protected by `config/discord.php` middleware (`web`, `auth`, `verified`, and `system.acp.view` for admin).

## Notifications pattern

`App\Notifications\SupportTicketAgentReply` implements `DiscordNotifiableNotification` and sends via `DiscordWebhookChannel` when an agent replies on a ticket (category `support_ticket_reply`). Copy this pattern for other high-value notifications.
