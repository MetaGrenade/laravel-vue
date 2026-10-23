# API keys

An **API key** lets a script or another service use the API as a person, without their password. Anyone signed in can make their own in **Settings → API keys**; each key is limited to the **scopes** they tick, **expires** when they say, and can be **revoked** at any moment. Staff make and manage keys for people in **Admin → Tokens**.

A key is a Laravel Sanctum personal access token. What this page adds is the self-service page, a fixed list of scopes that the API really enforces, a choice of lifetimes, and a revocation that really stops the key.

> **Status:** built: the settings page, scopes enforced on every API route that takes a key, expiry choices, revoke and remove, a limit on how many keys a person has, per-key usage, the ACP form using the same scopes, the data export, and the OpenAPI documentation. **Not built:** an email when a key is made, rotating a key in one step, limits a customer sets on their own key, and a least-privilege default for keys made through `POST /api/v1/auth/token` (see [not built](#not-built)).

## Making and using a key

1. **Settings → API keys → Create key.** Give it a **name** (so you can tell your keys apart), tick what it **can do**, and choose when it **expires** (30 days, 90 days, 1 year, or never, if the site allows that).
2. The key is shown **once**, straight after, with a copy button. Only a hash of it is kept, so nobody (including staff) can read it again. If it is lost, revoke it and make another.
3. Send it as a bearer token:

```bash
curl https://your-site.test/api/user \
  -H "Authorization: Bearer 12|abc..." \
  -H "Accept: application/json"
```

A key that has expired or been revoked gets a `401`. A key that lacks the scope a route needs gets a `403` that says which:

```json
{ "message": "This API key does not have the \"blog:write\" scope.", "scope": "blog:write" }
```

The page lists every key a person has, newest first: its scopes, when it was created, when it was last used, when it ends (or that it was revoked), and how many calls it made this month. Calls made with any key count toward the account's [metered usage](usage.md), and the page shows that meter at the top.

## Scopes

A scope is what a key may be used for. They are listed in `config/api.php` under `scopes`:

| Scope | What it lets a key do |
|---|---|
| `profile:read` | Read the owner's profile (`GET /api/user`, `GET /api/v1/profile`). |
| `blog:write` | Write, edit, delete, react to and report blog comments, and follow a post's comments. |
| `polls:vote` | Vote in a poll. |
| `forum:write` | Start threads, reply, edit posts, follow threads. |
| `forum:moderate` | Publish, lock, pin and delete threads. Offered only to people with the `admin`, `editor` or `moderator` role (the route also still needs the role). |
| `support:read` | Open a support ticket and download its attachments. |
| `support:write` | Open tickets, reply, change a ticket's status, reopen it, rate the help. |

Things to know:

- **A scope narrows; it never adds.** A key does no more than its owner can. A member's key with `forum:moderate` (which they are not offered) would still be refused by the role check.
- **Reading public things needs no key.** Blog posts, forum threads and polls are public to read; a key is only for what needs signing in.
- **`*` is full access**: every scope, including ones added later. Only staff can give it (in Admin → Tokens), and `POST /api/v1/auth/token` still gives it when it is not asked for fewer. The settings page never offers it.
- **A key can always remove itself** (`DELETE /api/v1/auth/token`), whatever it is limited to.
- **Enforced before the lookup.** The check sits in the middleware priority list ahead of route-model binding (`bootstrap/app.php`), after authentication and the usage meter. A key that may not read tickets is told so whether or not the ticket it asked for exists, so it cannot find out which ticket numbers are in use. A refused request still counts as a call, like any other request the server had to answer.
- **People signed in with a session** (the site's own pages calling the API) are not limited by scopes.

### Adding a scope

1. Add it to `scopes` in `config/api.php` with a label and a one-line description (and `roles` if it is for some people only).
2. Put `scope:your:scope` on the routes that need it in `routes/api.php`; with several names (`scope:a,b`) holding any one is enough.
3. Run `php artisan api:docs` to refresh the OpenAPI document, which reads each operation's scope from the routes.

Tests fail if a route that takes a key names no scope (so a new route cannot be left open by accident), if a route names a scope the site does not have, if a scope is not used by any route, or if the documentation and the routes disagree.

## When a key stops working

| It stops when | How |
|---|---|
| It **expires** | At the moment it ends. Sanctum checks `expires_at` on every request. |
| It is **revoked** | At once. Revoking sets `revoked_at` and the key stays on the list as *Revoked*, with what it did. `AppServiceProvider` registers a Sanctum callback that refuses a revoked key; before it existed `revoked_at` was only a label and a revoked key kept working. |
| It is **removed** | The row and its activity log are deleted. What the account used stays in its monthly total. |
| Its owner is **deleted** | Sanctum no longer finds an owner for it. |

The lifetimes offered, and whether "never" is among them, are set in `config/api.php`:

| Setting | What it is | Environment |
|---|---|---|
| `keys.max_active` | How many keys that can still be used a person may have at once (revoked and expired keys do not count). Making one past it is refused with a message. Counted under a lock on the person's row, so two requests at the limit cannot both make the last key. | `API_KEYS_MAX` (10) |
| `keys.expiry_days` | The lifetimes offered, in days (30, 90, 365). | |
| `keys.default_expiry_days` | The one chosen to begin with (90). | |
| `keys.allow_no_expiry` | Whether "never" is offered. A site that offers no lifetime at all must allow it, or nobody could make a key. | `API_KEYS_ALLOW_NO_EXPIRY` (true) |

Making keys is also **rate limited** (5 a minute and 20 an hour per person, the `api-keys` limiter), and needs a **verified email address** where the site asks for one (taking a key away never does).

Sanctum's own `sanctum.expiration` (in minutes, `null` by default) still applies on top of a key's own end, if you set it. Expired keys are not deleted by themselves: `php artisan sanctum:prune-expired --hours=24` does it, if you schedule it.

## Staff

**Admin → Tokens** (`tokens.acp.view`, `.create`, `.edit`) is unchanged except for the abilities field: it is the same scope list, with **Full access** on top, instead of free text. The ACP can also set a key's **hourly and daily quota** (the existing per-token rate limit); a person sees these on their page as "Limited by staff", but cannot change them.

Revoking in the ACP now really revokes. **Restoring** a revoked token (clearing the revocation in the edit dialog) makes it work again.

## Keys made over the API

`POST /api/v1/auth/token` (email, password, optional `device_name` and `abilities`) still makes a key, and it appears on the person's page. What changed:

- `abilities`, if sent, must be scopes the site has (or `*`), and must not be empty. Anything else is a `422`. Left out, the key has `*`, as before.
- `DELETE /api/v1/auth/token` now **revokes** the key instead of deleting it (it used to answer `500` after deleting it, because of a wrong return type, and the request could not be logged against a key that was gone). It stays on the person's page as revoked, where they can remove it.

## Upgrading from before scopes

Until now a token's `abilities` were free text that no route looked at. They are enforced now. A token with `['*']` (what `createToken()` gives by default, and what the ACP gave when the field was left empty) works as before. A token whose abilities name none of the scopes above, for instance `read` or `update`, **can no longer call anything that needs a scope**.

Find them and give them real scopes (or full access) in **Admin → Tokens**: the edit dialog ticks the scopes a token already holds, lists the abilities the site does not have, and drops them when you save. A person's own page shows such a key's abilities as they were written.

Run `php artisan api:docs` after deploying, so the generated OpenAPI document (cached in `storage/app/api-docs/openapi.json`) names each operation's scope.

## Privacy

- Only a SHA-256 hash of a key is stored. The secret is flashed to the next page once, held in the page's memory while the dialog is open, and never logged: the activity log keeps the route, status and a cleaned request body, not the `Authorization` header.
- A person's **data export** lists their keys (name, scopes, created, last used, ends, revoked) in both the JSON and the CSV, never the secret.
- Keys go with the account when it is deleted.

## Not built

- **An email when a key is made.** A new credential is a good moment to tell the person; the audit log (next in M3) is where staff will see it.
- **Rotating a key** in one step (make the new one, revoke the old one after a grace period). Today it is two steps.
- **A customer setting their own hourly or daily limit** on a key. The columns exist and the ACP sets them.
- **A least-privilege default and an expiry for keys made through `POST /api/v1/auth/token`.** Both would change what existing clients get, so they are left for a deliberate decision.
- **IP allow-lists** and **prefixes** for secret scanning (`SANCTUM_TOKEN_PREFIX` is available in `config/sanctum.php`; a distinctive prefix lets GitHub's secret scanning recognise a leaked key).

## Testing

`tests/Feature/Api/ApiKeyAuthenticationTest.php` (revoked, expired), `TokenScopesTest.php` (every route, order of middleware, behaviour), `AuthTokenTest.php`, `OpenApiScopesTest.php`; `tests/Feature/Settings/ApiKeysTest.php` and `ApiKeysExportTest.php`; `tests/Feature/Admin/TokenScopesAcpTest.php`.
