<?php

/*
|--------------------------------------------------------------------------
| Read-only impersonation ("view as")
|--------------------------------------------------------------------------
|
| Staff with `users.acp.impersonate` can see the site as one customer sees it, to help with a support case. It is read only: every
| request that is not a GET is refused, and a GET runs inside a transaction that is rolled back, so looking at a page changes
| nothing. See docs/impersonation.md.
|
*/

return [

    // How long one view lasts, from the moment it starts. It ends by itself then, and is recorded as expired.
    'minutes' => (int) env('IMPERSONATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Which pages can be opened while viewing as somebody
    |--------------------------------------------------------------------------
    |
    | Closed by default: a GET request is served only if it is for a route listed in `viewable` (by name, or by address for a route
    | with none); anything else, the admin area and the API included, is refused. A test fails when a GET route is in neither list, so a
    | page added later is decided on when it is added.
    |
    | `blocked` is what is kept from view and why. The reasons are the point: a page goes here when it would hand over a secret, change
    | something somewhere a rollback cannot reach, or is about signing in.
    |
    */

    'viewable' => [
        // The customer's own account.
        'dashboard',
        'profile.edit',
        'password.edit',
        'appearance',
        'settings',
        'settings.notifications.edit',
        'settings.addresses.index',
        'settings.standing',
        'settings.api-keys.index',
        'settings.billing.index',
        'settings.billing.invoices.index',
        'settings.billing.payment-methods.index',
        'privacy.index',
        // The customer's own messages. Sending is a POST, which is refused.
        'messages.index',
        'messages.show',
        // What somebody who has not verified their address is sent to, and so what they see (the button on it is a POST, which is refused).
        'verification.notice',
        'shop.orders',
        'shop.wishlist',
        'support.tickets.show',
        'forum.mentions.index',
        'forum.threads.create',
        'forum.posts.history',

        // What anyone can open.
        'home',
        'members.index',
        'members.show',
        'pricing',
        'search',
        'search.results',
        'blogs.index',
        'blogs.feed',
        'blogs.view',
        'blogs.comments.index',
        'forum.index',
        'forum.boards.show',
        'forum.threads.show',
        'forum.attachments.show',
        'forum.following',
        'shop.index',
        'shop.packages',
        'shop.packages.show',
        'shop.products.show',
        'shop.cart',
        'support',
        'api.docs',
        'api.docs.schema',
        'sitemap',
        'robots',
        'webmanifest',
        'sanctum.csrf-cookie',
        'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | What a page asks for by itself
    |--------------------------------------------------------------------------
    |
    | A page makes some requests on its own as it opens, with nobody having done anything: asking to listen for live updates is one
    | (the browser POSTs to `broadcasting/auth` for each page). They are turned away like any request that is not a GET, but they are
    | not written to the audit log: the person did not do them, and an entry for each would bury the ones they did.
    |
    */

    'background' => [
        'broadcasting/auth',
    ],

    'blocked' => [
        // Secrets. The security page sends the recovery codes, a pending two-factor secret and the ids of the other sessions.
        'security.edit',

        // Files. A download is the customer's own data (or what they bought, and it counts against their allowance).
        'privacy.exports.download',
        'settings.billing.invoices.download',
        'support.attachments.download',
        'shop.downloads.show',

        // Places that call Stripe or Tebex and can change something there, which a rollback does not reach.
        'shop.checkout',
        'shop.checkout.complete',
        'shop.tebex.continue',
        'cashier.payment',

        // Signing in and the links that sign somebody in or change their account: viewing as one person must not become being another.
        'login',
        'register',
        'password.request',
        'password.reset',
        'password.confirm',
        'two-factor.login',
        'verification.verify',
        'oauth.redirect',
        'oauth.callback',

        // Drafts held back by a link, and live updates (which a read-only view has no use for).
        'blogs.preview',
        'broadcasting/auth',
    ],

];
