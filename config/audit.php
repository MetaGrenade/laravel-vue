<?php

use App\Models\AutomodRule;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Models\Refund;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | The admin audit log
    |--------------------------------------------------------------------------
    |
    | Every change a signed-in person makes in the admin area (a POST, PUT, PATCH or DELETE to an `acp/` address) is written to the
    | `audit_logs` table: who, what, to what, from where, and whether it worked. See docs/audit-log.md.
    |
    | `retention_days` is how long entries are kept: 0 keeps them for ever. The log is append-only, and pruning (the daily
    | `PruneAuditLogs` job) is the only thing that deletes from it.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | What is recorded about the changes
    |--------------------------------------------------------------------------
    |
    | `models` are the records whose changes are written into the entry for the request that made them, as what they were and what they
    | became, so the log can say "role changed from editor to admin" and not only "an account was edited". For each: `label` is the
    | attribute that names the record in the log (kept as it was then, so a deleted record still reads right), `ignore` are
    | attributes whose change is not worth an entry (a change to nothing else is skipped), and `hide` are attributes whose change is
    | recorded but whose values are not (secrets). Any other model can be added here; attributes a model lists in `$hidden` are
    | hidden too.
    |
    */

    'models' => [
        User::class => [
            'label' => 'nickname',
            'ignore' => ['updated_at', 'last_activity_at', 'remember_token'],
            'hide' => ['password', 'two_factor_secret', 'two_factor_recovery_codes'],
        ],
        Role::class => ['label' => 'name'],
        AutomodRule::class => ['label' => 'name', 'ignore' => ['updated_at', 'hits', 'last_hit_at']],
        Permission::class => ['label' => 'name'],
        SystemSetting::class => ['label' => 'key'],
        SubscriptionPlan::class => ['label' => 'name'],
        Entitlement::class => ['label' => 'key'],
        Coupon::class => ['label' => 'code'],
        GiftCard::class => ['label' => 'last_four', 'hide' => ['code_hash']],
        Order::class => ['label' => 'public_id'],
        Refund::class => ['label' => 'id'],
        PersonalAccessToken::class => ['label' => 'name', 'ignore' => ['updated_at', 'last_used_at'], 'hide' => ['token']],
    ],

    // An attribute whose name contains one of these is hidden, whatever the model says (so is `token` and anything ending `_token`): a
    // secret added later is never written down.
    'hide_containing' => ['password', 'secret', 'api_key', 'recovery_code'],

    // The most changes kept in one entry (a bulk action can touch hundreds), and the longest value kept before it is cut short.
    'max_changes' => 100,
    'max_value_length' => 300,

    /*
    |--------------------------------------------------------------------------
    | Names for actions
    |--------------------------------------------------------------------------
    |
    | An action is named by its route (`acp.users.ban` is `users.ban`). These give the ones people look for a plain name; any other
    | reads as its route name.
    |
    */

    'events' => [
        'users.update' => 'Edited a user',
        'users.destroy' => 'Deleted a user',
        'users.verify' => 'Verified a user\'s email address',
        'users.warn' => 'Warned a user',
        'forums.review.replies.approve' => 'Approved a held reply',
        'forums.review.replies.reject' => 'Rejected a held reply',
        'forums.review.threads.approve' => 'Approved a held thread',
        'forums.review.threads.reject' => 'Rejected a held thread',
        'forums.review.bulk' => 'Approved or rejected several held posts',
        'forums.automod.store' => 'Added an automod rule',
        'forums.automod.update' => 'Changed an automod rule',
        'forums.automod.enabled' => 'Turned an automod rule on or off',
        'forums.automod.destroy' => 'Deleted an automod rule',
        'users.ban' => 'Banned a user',
        'users.unban' => 'Lifted a ban',
        'users.bulk-update' => 'Changed several users at once',
        'acl.roles.store' => 'Made a role',
        'acl.roles.update' => 'Changed a role',
        'acl.roles.destroy' => 'Deleted a role',
        'acl.permissions.store' => 'Made a permission',
        'acl.permissions.update' => 'Changed a permission',
        'acl.permissions.destroy' => 'Deleted a permission',
        'system.update' => 'Changed the system settings',
        'tokens.store' => 'Made an API token for somebody',
        'tokens.update' => 'Changed an API token',
        'tokens.revoke' => 'Revoked an API token',
        'tokens.destroy' => 'Deleted an API token',
        'commerce.orders.refunds.store' => 'Refunded an order',
        'billing.plans.store' => 'Made a subscription plan',
        'billing.plans.update' => 'Changed a subscription plan',
        'billing.plans.destroy' => 'Deleted a subscription plan',
        'billing.webhooks.replay' => 'Ran a payment webhook again',
        'users.impersonate' => 'Tried to start viewing as a customer',
        'impersonation.started' => 'Started viewing as a customer',
        'impersonation.viewed' => 'Opened a page while viewing as a customer',
        'impersonation.blocked' => 'Was stopped from opening or changing something while viewing as a customer',
        'impersonation.stopped' => 'Stopped viewing as a customer',
    ],

];
