# API keys

An **API key** lets a script or another service use the API as a person, without their password. Anyone signed in can make their own in **Settings → API keys**; each key is limited to the **scopes** they tick, **expires** when they say, can be given **limits** on how many calls it makes, can be **rotated** in one step, and can be **revoked** at any moment. They are **emailed** each time a key is made on their account. Staff make and manage keys for people in **Admin → Tokens**.

A key is a Laravel Sanctum personal access token. What this page adds is the self-service page, a fixed list of scopes that the API really enforces, a choice of lifetimes, and a revocation that really stops the key.

> **Status:** built: the settings page, scopes enforced on every API route that takes a key, expiry choices, revoke and remove, a limit on how many keys a person has, per-key usage, an email when a key is made, one-step rotation with a grace period, limits an owner sets on a key, least-privilege defaults and an expiry for keys made through `POST /api/v1/auth/token`, the ACP form using the same scopes, the data export, and the OpenAPI documentation. **Not built:** see [below](#not-built).

## Making and using a key

1. **Settings → API keys → Create key.** Give it a **name** (so you can tell your keys apart), tick what it **can do**, choose when it **expires** (30 days, 90 days, 1 year, or never, if the site allows that), and, if you like, **limit its calls** an hour and a day.
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

The page lists every key a person has, newest first: its scopes, when it was created, when it was last used, when it ends (or that it was revoked or is being replaced), its limits, and how many calls it made this month. Calls made with any key count toward the account's [metered usage](usage.md), and the page shows that meter at the top.

## An email when a key is made

Whoever makes a key on an account, the owner gets an email saying what was made, how (in settings, by signing in over the API, by rotating another key, or by staff), from which address, what it can do and when it ends, with a link to **Settings → API keys** and the advice to revoke it and change their password if it was not them. It is the moment to notice a key someone else made.

- It is sent for every way of making a key, including keys staff make in **Admin → Tokens** and a key made to replace another when one is rotated. Nothing is sent when no key is made (a refusal, an error) or when a key is revoked, removed or limited.
- It is an **account security notice**, so it is sent by email whatever the person's notification settings say, like the billing notices (it does not use the notification preferences). It is queued on the `mail` queue.
- It **never holds the key itself.** The key's name is shown inside a code span so that nothing in it (a link, markup) is read as anything but text: staff can name a key on somebody else's account.
- A mail that cannot be sent is **reported** and does not undo the key: its secret goes out with the response that made it, so failing then would lose it.

## Rotating a key

**Rotate** on a key's card makes a new key like it and lets the old one go on working for a while, so whatever uses it has time to be given the new one. The new key has the same name, scopes and limits, and lasts as long from today as the old one was made to (a key that never ends gets one that never ends, or the default lifetime where the site does not allow that). The new secret is shown once, as for any key.

Choose how long the old key keeps working: **not at all** (it stops at once), 1 hour, 1 day or 7 days (`keys.rotation_grace_hours`; the default offered is `keys.default_rotation_grace_hours`, 1 day). The old key's end is brought forward to the end of the grace period (never put back, so a key that was due to end sooner still does), `rotated_at` is set, and it is listed as **Being replaced** until it ends. A key that is being replaced cannot be rotated again. It can still be revoked or removed.

Things to know:

- **There has to be room for both.** The old key still counts toward the limit on keys during its grace period, so with a grace period a person at the limit must revoke a key first. Rotating with **no** grace period needs no room: the new key takes the old one's place. This is also what stops rotation from being a way around the limit: rotating over and over cannot pile up keys.
- **Limits that staff set stay staff's** on the new key (see [limits](#limiting-a-key)); rotating is no way round them.
- **What the old key held is not copied blindly:** abilities the site no longer has are dropped, and a key that holds none the site recognises is refused (make a new key instead).
- **It needs the person's own session.** There is no endpoint a key can call to rotate itself: a leaked key could then renew itself for ever, which would make expiry no protection at all.
- Rotating is held to the rules for making a key: the verified-address rule and the same rate limit.

## Limiting a key

A key can be given a ceiling on how many calls it makes **an hour** and **a day**, so a script that goes wrong cannot use up the whole account's allowance. Set it when the key is made, or later with **Limits** on the key's card. Either box can be left empty for no limit; a limit is a whole number from 1 to 10,000,000. When a key reaches one it gets a `429` with a `Retry-After` and is not counted against the account's allowance (this is the per-token quota that staff could always set; see `ThrottleTokenUsage`).

**Staff limits win.** If staff set a key's limits in **Admin → Tokens**, the owner cannot change or remove them: the page says "Limited by staff" and the **Limits** button is gone (`limits_set_by_staff_at` records it). Changing a key's limits in the ACP makes them staff's; saving a key there without changing its limits does not; taking both off hands the key back to its owner. Limits that existed before this was built were all staff's, and the migration marks them so.

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
| `keys.max_active` | How many keys that can still be used a person may have at once, however they were made (on the page or by signing in over the API; revoked and expired keys do not count). Making one past it is refused with a message. Counted under a lock on the person's row, so two requests at the limit cannot both make the last key. | `API_KEYS_MAX` (10) |
| `keys.expiry_days` | The lifetimes offered, in days (30, 90, 365). | |
| `keys.default_expiry_days` | The one chosen to begin with (90). | |
| `keys.allow_no_expiry` | Whether "never" is offered. A site that offers no lifetime at all must allow it, or nobody could make a key. | `API_KEYS_ALLOW_NO_EXPIRY` (true) |
| `keys.rotation_grace_hours` | How long the old key may keep working after a rotation, in hours (0 for not at all): 0, 1, 24, 168. | |
| `keys.default_rotation_grace_hours` | The one chosen to begin with (24). | |

Making keys is also **rate limited** (5 a minute and 20 an hour, the `api-keys` limiter: counted for the person on the page, and for an email address from one place when signing in over the API), and needs a **verified email address** where the site asks for one (taking a key away never does).

Sanctum's own `sanctum.expiration` (in minutes, `null` by default) still applies on top of a key's own end, if you set it. Expired keys are not deleted by themselves: `php artisan sanctum:prune-expired --hours=24` does it, if you schedule it.

## Staff

**Admin → Tokens** (`tokens.acp.view`, `.create`, `.edit`) is unchanged except for the abilities field: it is the same scope list, with **Full access** on top, instead of free text. The ACP can also set a key's **hourly and daily quota** (the existing per-token rate limit); a person sees these on their page as "Limited by staff", and cannot change them (see [limiting a key](#limiting-a-key)). A key staff make for somebody emails them (see above).

Revoking in the ACP now really revokes. **Restoring** a revoked token (clearing the revocation in the edit dialog) makes it work again.

## Keys made over the API

`POST /api/v1/auth/token` (email, password, optional `device_name` and `abilities`) still makes a key, and it appears on the person's page. It goes through the same `ApiKeys::create()` as the page, so it has the same limits and nobody can get around them by using it instead:

- **The limit on keys** (`API_KEYS_MAX`) counts the keys made this way too. Past it the answer is a `422` with the message under `errors.limit`; give a key back (`DELETE /api/v1/auth/token`) or revoke one in Settings to make room.
- **A verified address** is needed where the site asks for one (a `422` under `errors.email`).
- **The `api-keys` rate limit** applies, counting failed attempts as well as successful ones, for each email address (without regard to its case) from each place; one address does not use up the allowance of others behind the same IP.
- **The owner is emailed**, as for any key.

**What a key gets when the request does not say.** Least privilege, and an end:

| Setting | What it is | Environment |
|---|---|---|
| `sign_in.scopes` | The scopes a key gets when `abilities` is not sent: a comma separated list from the scopes above, or `*` for every scope (how it used to be). Empty means `profile:read`. | `API_SIGN_IN_SCOPES` (`profile:read`) |
| `sign_in.expiry_days` | How long the key lasts, in days. Empty or `0` means no end, where the site allows keys that never end (otherwise `keys.default_expiry_days`). | `API_SIGN_IN_EXPIRY_DAYS` (90) |

A client that needs more than that sends `abilities` (any scopes the site has, or `*`): these are only the defaults. The response says what the key can do and when it ends:

```json
{ "token": "12|abc...", "abilities": ["profile:read"], "expires_at": "2027-01-21T12:00:00+00:00", "user": { "id": 1, "name": null, "email": "jane@example.com" } }
```

What else changed:

- `abilities`, if sent, must be scopes the site has (or `*`), and must not be empty. Anything else is a `422`.
- `DELETE /api/v1/auth/token` now **revokes** the key instead of deleting it (it used to answer `500` after deleting it, because of a wrong return type, and the request could not be logged against a key that was gone). It stays on the person's page as revoked, where they can remove it.

## Upgrading from before scopes

Until now a token's `abilities` were free text that no route looked at. They are enforced now. A token with `['*']` (what `createToken()` gives by default, and what the ACP gave when the field was left empty) works as before. A token whose abilities name none of the scopes above, for instance `read` or `update`, **can no longer call anything that needs a scope**.

Find them and give them real scopes (or full access) in **Admin → Tokens**: the edit dialog ticks the scopes a token already holds, lists the abilities the site does not have, and drops them when you save. A person's own page shows such a key's abilities as they were written.

Run `php artisan api:docs` after deploying, so the generated OpenAPI document (cached in `storage/app/api-docs/openapi.json`) names each operation's scope.

## Upgrading from before rotation and the sign-in defaults

- Run `php artisan migrate`: it adds two nullable columns to `personal_access_tokens` (`rotated_at` and `limits_set_by_staff_at`) and marks every limit that already exists as staff's.
- **A client that signs in over the API and sends no `abilities` now gets a key that can only read the profile, and that ends after 90 days.** It used to get full access that never ended. To keep the old behaviour set `API_SIGN_IN_SCOPES=*` and `API_SIGN_IN_EXPIRY_DAYS=0` (where `API_KEYS_ALLOW_NO_EXPIRY` allows it), or better, have the client send the `abilities` it needs and sign in again when its key ends.
- Everyone with a key made on their account will now get an email when another is made.

## Privacy

- Only a SHA-256 hash of a key is stored. The secret is flashed to the next page once, held in the page's memory while the dialog is open, and never logged: the activity log keeps the route, status and a cleaned request body, not the `Authorization` header.
- A person's **data export** lists their keys (name, scopes, created, last used, ends, revoked) in both the JSON and the CSV, never the secret.
- Keys go with the account when it is deleted.

## Not built

- **A key rotating itself over the API.** A leaked key could renew itself for ever, so rotation needs the person's own session. A service that must renew without a person should sign in again (`POST /api/v1/auth/token`) when its key is about to end.
- **Opting out of the email**, or choosing another channel. It is a security notice, sent by email always.
- **Two-factor authentication on `POST /api/v1/auth/token`.** It takes a password only, so an account with two-factor authentication can make a key without a code. Keys made on the settings page are made from a signed-in session, which has been through it.
- **A client that signs in at every start and never gives the key back** will meet the limit on keys (a `422`): it should reuse its key or call `DELETE /api/v1/auth/token`.
- **IP allow-lists** and **prefixes** for secret scanning (`SANCTUM_TOKEN_PREFIX` is available in `config/sanctum.php`; a distinctive prefix lets GitHub's secret scanning recognise a leaked key).

## Testing

`tests/Feature/Api/ApiKeyAuthenticationTest.php` (revoked, expired), `TokenScopesTest.php` (every route, order of middleware, behaviour), `AuthTokenTest.php` (the same rules as Settings, the defaults), `OpenApiScopesTest.php`; `tests/Feature/Settings/ApiKeysTest.php`, `ApiKeysExportTest.php`, `ApiKeyNotificationTest.php`, `ApiKeyRotationTest.php` and `ApiKeyLimitsTest.php`; `tests/Feature/Admin/TokenScopesAcpTest.php`; `tests/Feature/Database/PersonalAccessTokenRotationMigrationTest.php`.
