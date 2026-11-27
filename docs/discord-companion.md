# Discord companion integration

MetaForger ships the [`metagrenade/metaforge-discord`](https://github.com/MetaGrenade/MetaForge-DiscordBot/tree/main/laravel) package for role sync, bot webhooks, and Discord notification opt-in.

## Install

The package lives in the MetaForge-DiscordBot monorepo (`laravel/`). Because there is no root `composer.json` on that repository, this app registers an inline Composer `package` repository (see root `composer.json`) that clones `main` and autoloads `laravel/src/`.

```bash
composer install
php artisan vendor:publish --tag=metaforger-discord-config
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
| ACP sync status / manual sync / DLQ | `/acp/discord/sync` |
| User Discord notification opt-in | `/settings/discord-notifications` |

JSON APIs are provided by the package under `/discord/admin/*` and `/discord/user/notification-preferences`, protected by the middleware lists in `config/discord.php` (see below for the admin one).

### Who can change what

The admin routes need a member of staff with `system.acp.view`. That is enough to open the two admin pages and read their lists. **Every change needs `system.acp.edit`**: adding, changing or deleting a mapping, syncing a user, and retrying failures.

A mapping with **sync from Discord** on gives its site role to any member whose linked Discord account is given the Discord role it names. So a mapping is changed only by somebody who could give that role themselves ([acp-permissions.md](acp-permissions.md#giving-permissions)):

- **an administrator** maps any role;
- **anybody else** adds, changes or deletes a mapping only of a role whose every permission they hold, and never of `admin`, `editor` or `moderator`. The page offers them no other role. A mapping whose role is no longer there, they can only delete;
- **sync from Discord also takes the role away** from a linked account that lacks the Discord role. While somebody has the role, only an administrator can switch it on, or point a mapping that has it on at another role or Discord role. Whoever may change the mapping can switch it off or delete it;
- **linking a Discord account to a member by hand** (**Admin → Users → Edit**) is refused to them while a mapping that syncs from Discord names a role they could not give.

Think about who can hand out the mapped Discord role on your server: for the site, they hand out the site role.

The rules for the Discord pages are `App\Http\Middleware\RestrictDiscordAdminChanges`, the last entry of `admin_middleware`. The package runs the list it finds there, so if you published your own `config/discord.php` or changed the list, keep that entry.

These changes are **not** in the [audit log](audit-log.md), which records writes under `/acp` only.

### Sync dead-letter queue (operators)

When Discord role sync jobs fail after retries, rows land in `discord_sync_failures`. On **ACP → Discord sync**, the **Sync dead-letter queue** panel lists unresolved (and resolved) failures, shows the **Unresolved DLQ** count from `GET /discord/admin/sync/status`, and lets you **Retry batch** (same as `php artisan discord:retry-failures --limit=N`) or **Retry selected** rows via `POST /discord/admin/sync/failures/retry`. The list API accepts `?unresolved=1` to return only open rows; omit it for all rows (the ACP **Resolved** filter applies client-side on each page).

## Notifications pattern

`App\Notifications\SupportTicketAgentReply` implements `DiscordNotifiableNotification` and sends via `DiscordWebhookChannel` when an agent replies on a ticket (category `support_ticket_reply`). Copy this pattern for other high-value notifications.

## Moderation staff mirror

When a moderator records a warning, ban, unban, or content removal, `App\Support\Moderation\Moderation` dispatches `App\Events\ModerationDiscordMirror`. The companion listens when the class is listed in `config/discord.php` → `moderation_event_classes` (default via `DISCORD_MODERATION_EVENT_CLASSES`).

Set staff channel credentials and optional deep-link template in `.env` (see `.env.example`):

| Variable | Purpose |
|----------|---------|
| `DISCORD_MODERATION_MIRROR_ENABLED` | Master switch (companion respects this when posting) |
| `DISCORD_MODERATION_CHANNEL_ID` | Private staff channel (bot token path) |
| `DISCORD_MODERATION_WEBHOOK_URL` | Alternative webhook-only posting |
| `DISCORD_MODERATION_URL_TEMPLATE` | Embed link; `{user_id}` and `{action_id}` placeholders |
| `DISCORD_MODERATION_STAFF_REASON_IN_EMBED` | Include truncated public reason in staff embeds (default off) |

Full wiring and security notes: [MetaForge-DiscordBot docs/moderation-discord.md](https://github.com/MetaGrenade/MetaForge-DiscordBot/blob/main/docs/moderation-discord.md).

Optional bot ingest without host events: `POST /discord/bot/moderation` (HMAC, same as other bot routes).
