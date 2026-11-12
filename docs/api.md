# The API

A JSON API under `/api/v1`. Anyone can read the public content (blog posts and their comments, forum threads, polls). With an API key a script can act as the person the key belongs to: comment, post in the forum, open a support ticket, vote. This page is how to call it, what comes back and what limits to expect. Every endpoint's inputs and outputs are in the **OpenAPI document** ([below](#the-openapi-document)); how keys are made, limited and revoked is [api-keys.md](api-keys.md).

> **Status:** written after using the API as a client against a throwaway copy of the site (its own database, `APP_DEBUG=false`): signing in and out, reading the lists, the profile, commenting, reacting, editing and deleting a comment, following a post, starting a thread, replying, editing a post, marking a solution, locking, pinning and deleting a thread as staff, opening a ticket, and then every kind of error and the rate limit. **Not done by hand:** a poll vote, a ticket reply, closing, reopening and rating a ticket, an attachment download, reporting a comment (only its refusal was seen), publishing and unpublishing a thread; they are covered by the automated API tests only. `tests/Feature/ApiReferenceDocsTest.php` keeps [the endpoint table](#every-endpoint) equal to the routes and the OpenAPI document equal to the routes. Three things do not work as the code says they should; they are under [Known problems](#known-problems-in-10).

## The short version

- The address is `https://your-site/api/v1/...`.
- **Send `Accept: application/json` every time.** Without it a request with no key or a bad one is answered with a redirect to the sign-in page, and a missing record with an HTML page.
- Reading public content needs no key. Everything else needs one, sent as `Authorization: Bearer <key>`.
- A key is made in **Settings → API keys** by the person it is for, or by signing in over the API. It is limited to the **scopes** it was given, and expires.

```bash
# Sign in: the answer holds the key, shown once
curl -X POST https://your-site/api/v1/auth/token \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"…","device_name":"my script","abilities":["blog:write"]}'

# Read the newest posts (no key needed)
curl https://your-site/api/v1/blogs -H "Accept: application/json"

# Write a comment with the key
curl -X POST https://your-site/api/v1/blogs/a-first-post/comments \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer 12|abc…" \
  -d '{"body":"Hello from a script","captcha_token":"x"}'
```

## The OpenAPI document

`/api/docs/openapi.json` is an OpenAPI 3.0.3 description of every `/api/v1` operation: what it takes, what it can answer, and the scope it needs. Open it in any tool that reads OpenAPI (Swagger Editor, Postman, Insomnia, a code generator). It is the reference for the fields of each request and response; this page does not repeat them.

- **It is written once and then served from a file**, `storage/app/api-docs/openapi.json`, until the file is made again. A site that is upgraded, or has new routes added, shows the old description until someone runs `php artisan api:docs`. Put it in the deploy ([Every deploy](deployment.md#every-deploy)); it was measured on a development checkout whose file was five operations behind its routes.
- **It carries the site's address** (`servers`, from `APP_URL` when the file was written). Run `php artisan api:docs` again after changing `APP_URL` or `APP_NAME`.
- It is public and the same for everyone, so it is sent with `Cache-Control: public, max-age=3600` and an `ETag` ([http-caching.md](http-caching.md)).
- Each operation's required scope is read from the routes when it is written, so the document cannot say a scope the API does not ask for. A test also fails when the document and the routes list different operations.

`/api/docs` is a page that shows the same document with Swagger UI. It is blank on a default install ([Known problems](#known-problems-in-10)); use the JSON in a tool of your own.

## Signing in and keys

- A person makes a key in **Settings → API keys**: a name, the scopes, when it expires, and optionally a limit on its calls. It is shown once.
- `POST /api/v1/auth/token` makes one from an email address and password. Without `abilities` the key gets only `profile:read` and lasts 90 days (`API_SIGN_IN_SCOPES`, `API_SIGN_IN_EXPIRY_DAYS`). It counts toward the limit of ten keys a person may have (`API_KEYS_MAX`), is rate limited, needs a verified address where the site asks for one, and the owner is emailed. It asks for no second factor ([api-keys.md](api-keys.md#keys-made-over-the-api)).
- `DELETE /api/v1/auth/token` revokes the key that makes the call, whatever scopes it holds. A revoked or expired key is answered with `401`.
- **A scope narrows; it never adds.** A key can do no more than its owner can. The scopes are `profile:read`, `blog:write`, `polls:vote`, `forum:write`, `forum:moderate`, `support:read` and `support:write` ([the table](api-keys.md#scopes)). A request for something the key has no scope for is refused with a `403` that names the scope, before the record it asked for is looked up, so a key cannot find out what exists.

## Every endpoint

*Scope* is what the key must hold: `public` needs no key, `any key` needs one and no scope. *Section* is the part of the site whose switch (**Admin → System settings**) turns the endpoint off: with it off the endpoint answers `404`. The calls that need `forum:moderate` also need the `admin`, `editor` or `moderator` role. The table is checked against the routes by a test.

| Method | Address | Scope | Section | What it does |
|---|---|---|---|---|
| `POST` | `/api/v1/auth/token` | public | — | Sign in with an email and password and get a key. |
| `DELETE` | `/api/v1/auth/token` | any key | — | Revoke the key that makes the call. |
| `GET` | `/api/v1/profile` | `profile:read` | — | The key owner's id, nickname and email. |
| `GET` | `/api/user` | `profile:read` | — | The same, at the address older clients use. |
| `GET` | `/api/v1/blogs` | public | blog | Published posts, newest first, 15 to a page. |
| `GET` | `/api/v1/blogs/{blog}` | public | blog | One published post, by its slug. |
| `GET` | `/api/v1/blogs/{blog}/comments` | public | blog | The comments of a post, 10 to a page by default (`per_page` 1 to 50, `sort` `oldest`, `newest` or `top`). |
| `POST` | `/api/v1/blogs/{blog}/comments` | `blog:write` | blog | Write a comment. Needs a `captcha_token` ([below](#things-that-surprise-people)). |
| `PATCH` | `/api/v1/blogs/{blog}/comments/{comment}` | `blog:write` | blog | Edit a comment. |
| `DELETE` | `/api/v1/blogs/{blog}/comments/{comment}` | `blog:write` | blog | Delete a comment. |
| `POST` | `/api/v1/blogs/{blog}/comments/{comment}/react` | `blog:write` | blog | Like or dislike a comment. |
| `POST` | `/api/v1/blogs/{blog}/comments/{comment}/report` | `blog:write` | blog | Report a comment to the moderators (`reason_category` is required). |
| `POST` | `/api/v1/blogs/{blog}/comments/subscriptions` | `blog:write` | blog | Follow the comments of a post. |
| `DELETE` | `/api/v1/blogs/{blog}/comments/subscriptions` | `blog:write` | blog | Stop following them. |
| `GET` | `/api/v1/polls` | public | — | The published and closed polls, newest first, with their options and vote counts. 15 to a page (`?per_page=` up to 50). With a key, each poll says which options the key's owner voted for. |
| `GET` | `/api/v1/polls/{poll}` | public | — | One poll. |
| `POST` | `/api/v1/polls/{poll}/vote` | `polls:vote` | — | Vote in a poll. |
| `GET` | `/api/v1/forum/threads` | public | forum | Published threads, most recently active first, 15 to a page. |
| `GET` | `/api/v1/forum/threads/{thread}` | public | forum | One published thread. |
| `POST` | `/api/v1/forum/boards/{board}/threads` | `forum:write` | forum | Start a thread in a board. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}` | `forum:write` | forum | Change a thread's title. |
| `PUT` | `/api/v1/forum/boards/{board}/threads/{thread}/solution` | `forum:write` | forum | Mark the reply that answers the thread, or clear it. |
| `POST` | `/api/v1/forum/boards/{board}/threads/{thread}/posts` | `forum:write` | forum | Reply to a thread. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/posts/{post}` | `forum:write` | forum | Edit a post. |
| `POST` | `/api/v1/forum/boards/{board}/threads/{thread}/subscriptions` | `forum:write` | forum | Follow a thread. |
| `DELETE` | `/api/v1/forum/boards/{board}/threads/{thread}/subscriptions` | `forum:write` | forum | Stop following it. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/publish` | `forum:moderate` | forum | Publish a thread. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/unpublish` | `forum:moderate` | forum | Unpublish a thread. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/lock` | `forum:moderate` | forum | Lock a thread. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/unlock` | `forum:moderate` | forum | Unlock it. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/pin` | `forum:moderate` | forum | Pin a thread. |
| `PATCH` | `/api/v1/forum/boards/{board}/threads/{thread}/unpin` | `forum:moderate` | forum | Unpin it. |
| `DELETE` | `/api/v1/forum/boards/{board}/threads/{thread}` | `forum:moderate` | forum | Delete a thread. |
| `POST` | `/api/v1/support/tickets` | `support:write` | support | Open a ticket. |
| `GET` | `/api/v1/support/tickets/{ticket}` | `support:read` | support | One of your tickets, with its messages. |
| `POST` | `/api/v1/support/tickets/{ticket}/messages` | `support:write` | support | Reply on a ticket. |
| `PATCH` | `/api/v1/support/tickets/{ticket}/status` | `support:write` | support | Close a ticket. |
| `PATCH` | `/api/v1/support/tickets/{ticket}/reopen` | `support:write` | support | Reopen a closed ticket. |
| `POST` | `/api/v1/support/tickets/{ticket}/rating` | `support:write` | support | Rate the help after the ticket is resolved. |
| `GET` | `/api/v1/support/attachments/{attachment}` | `support:read` | support | Download a file attached to a ticket. |

Records are named by their slug (a post, a thread, a board, a poll) or their number (a comment, a post in a thread, a ticket, an attachment).

## What comes back

- **Lists** (the blog, its comments, the threads) are Laravel pages: `data` (the rows), `links` (`first`, `last`, `prev`, `next`) and `meta` (`current_page`, `last_page`, `per_page`, `total`, and a `links` list of page buttons). Ask for another page with `?page=2`. The posts and the threads come 15 to a page and there is no parameter to change that (`?per_page=3` is ignored). The comments are the exception: 10 to a page unless you send `?per_page=`, which is held to 1 to 50 (`100` gives 50, and `0` or text gives 1), and `?sort=` takes `oldest` (the default), `newest` or `top` (most liked; any other word is `oldest`). The polls are a page too, with the same `links` and `meta`, but the rows are under `polls` and not `data` (the key it always had, so a script that read `polls` keeps working, now for the first page: follow `links.next` for the rest). They come 15 to a page, and `?per_page=` is held to 1 to 50 as it is for the comments. A poll made in the same second as another still has one place in the order, so none is on two pages or on none.
- **Dates** are ISO 8601 with an offset, such as `2026-11-08T09:17:24+00:00`. The `expires_at` of a new key is UTC with a trailing `Z`.
- **There is no one envelope.** What the calls that were tried answered:

| Call | Status | Body |
|---|---|---|
| Sign in | `201` | `token`, `abilities`, `expires_at`, `user` |
| Sign out | `204` | nothing |
| Profile | `200` | `data` |
| Polls | `200` | `polls`, `links` and `meta` (the list), `poll` (one) |
| Write, edit or react to a comment | `201`, `200` | the comment itself |
| Delete a comment | `200` | `message` and `id` |
| Follow or unfollow a post, a thread | `200` | `subscribed` (and `subscribers_count` for a post) |
| Start a thread | `201` | `thread` and `initial_post` |
| Reply to a thread | `201` | the post itself |
| Edit a post, mark a solution | `200` | `data` |
| Lock, unlock, pin, unpin, publish, unpublish | `200` | `id`, `is_published`, `is_locked`, `is_pinned` |
| Delete a thread | `204` | nothing |
| Open a ticket | `201` | the ticket itself, with its `messages` |

  For any other call the OpenAPI document says which, and a test fails when it and the routes disagree about which operations exist.

### Things that surprise people

- **A comment needs a `captcha_token`.** The field is required, and for a key it can be any text: the check against the page's own token is skipped when there is no session, which is the case for a key. Send something. A forum post has no such field.
- **The slug of a new thread is not the title's.** A random ending is added (`from-the-api-peT2Du`). Read `slug` from the answer; do not build it.
- **What you just made may show empty defaults.** The answer to starting a thread has `is_locked`, `is_pinned` and `views` as `null`, and a new ticket has `status` as `null`. Fetch it again for the stored values.
- **A user object is `id` and `nickname`.** A member has no other name, so there is no `name`; the support endpoints say `nickname` too. It has an `email` only for the key's owner, and not always then: the author of a reply you have just made comes back with `email` as `null`.

## Errors

Errors are JSON, with `Accept: application/json`, and always have a `message`. Match on the **status** and the field names under `errors`, not on the words: the text of a `404` is Laravel's, and names the kind of record.

| Status | When | Body |
|---|---|---|
| `401` | No key, or one that is wrong, expired or revoked | `{"message":"Unauthenticated."}` |
| `403` | The key lacks the scope | `{"message":"This API key does not have the \"polls:vote\" scope.","scope":"polls:vote"}` |
| `403` | The key's owner may not do it (a policy, or the staff role the moderation calls need) | `message` |
| `404` | No such record or address, or the section is switched off | `{"message":"No query results for model [App\\Models\\Blog] nope"}` |
| `405` | Wrong method | `{"message":"The DELETE method is not supported for route api/v1/blogs. Supported methods: GET, HEAD."}` |
| `422` | The request was refused | `{"message":"…","errors":{"email":["…"]}}`, one list for each field |
| `429` | A limit ([below](#limits)) | `message`, and `Retry-After` in seconds |

With `APP_DEBUG=true` every error also carries `exception`, `file`, `line` and the whole `trace`. Production must have it `false` ([configuration.md](configuration.md)).

## Limits

There are four, and a `429` with a `Retry-After` says you hit one:

| Limit | Applies to | The answer |
|---|---|---|
| **Per minute, for the route** | Every call that needs a key, except the profile and signing out (the numbers are below) | `{"message":"Too Many Attempts."}`, with `X-RateLimit-Limit` and `X-RateLimit-Remaining` |
| **Per key, an hour and a day** | A key whose owner or staff set them ([api-keys.md](api-keys.md#limiting-a-key)) | `message`, `limit`, `usage`, `period` (`hour` or `day`) and `retry_after` |
| **Per account, a month** | Every call made with any key; the allowance comes from the account's plan, and a site can leave it unlimited ([usage.md](usage.md)) | `message`, `meter`, `limit`, `used` and `resets_at`. An account with an allowance is told where it stands in `X-Usage-Limit`, `X-Usage-Remaining` and `X-Usage-Resets-At` |
| **Signing in** | `POST /api/v1/auth/token`: 5 a minute and 20 an hour for one address from one place, and 20 a minute from one place | `Too Many Attempts.` |

The per-minute numbers are in `config/rate-limits.php` (`api`). Each is counted for the account, so two keys of one account share it, and each is a count of its own, so a call to one part of the API does not use up what another part has left. A call that lists two limits counts once against each. Comments and following a post 20; writing a comment also the `blog-comments` limiter (`BLOG_COMMENT_RATE_LIMIT`, 5 a minute, which the site's own pages share); forum writes 30; moderation 20; a poll vote 20; every support call together 20 and, inside that, opening, replying to, closing or reopening a ticket 10, reading a ticket or downloading an attachment 30 (above the 20, so it never turns a read away) and a rating 5. **The reading of public content has no limit from the site at all.** If it needs one, set it at the proxy or the CDN in front.

The hourly and daily limit of a key is counted from the key's own activity log ([api-keys.md](api-keys.md)). A call that is turned away before the record the address names is looked up (a record that is not there, a route over its per-minute limit, a key without the scope) is not written to that log, so it does not count toward the key's hour or day, and it does not show in the log either ([architecture.md](architecture.md#an-api-request)). The month's allowance is decided earlier, before the scope is checked and the record is looked up.

A request that fails on the server (a `5xx`) or is told to slow down (a `429`) does not count toward the month.

## From a browser

The API answers any origin: it sends `Access-Control-Allow-Origin: *` and answers a preflight `OPTIONS`. That is Laravel's default, as the site has no `config/cors.php`. A key is a bearer token, not a cookie, so a page on another site can use it only if it holds the key. If that is more than you want, add a `config/cors.php` with the origins you allow.

## Known problems in 1.0

Found while writing this page. They are code, not documentation, and each has a task to fix it. When one is fixed, this section loses its line.

1. **`/api/docs` is blank.** The page loads Swagger UI from a CDN with a script that has no nonce, and the site's Content Security Policy blocks all three, so the page stays empty. `/api/docs/openapi.json` works.

## Testing

`tests/Feature/ApiReferenceDocsTest.php` reads the table above and fails for a route that is not in it, a row for a route that does not exist, a scope that is not the one the route asks for, a section that is not the route's, and an OpenAPI document that does not describe exactly the `/api/v1` routes. `tests/Feature/Api/` holds the tests of the behaviour: `TokenScopesTest`, `OpenApiScopesTest`, `AuthTokenTest`, `ApiKeyAuthenticationTest`, `TokenQuotaTest`, `ApiRateLimitsTest` (each per-minute limit is a count of its own), one for each area (`BlogApiTest`, `ForumWriteApiTest`, `PollApiTest`, `SupportApiTest`), and `UserNicknameApiTest` (what a user looks like in an author, the profile and the sign-in answer). `tests/Feature/RouteThrottlesTest.php` fails for a route that is throttled with bare numbers (`throttle:20,1`) or by a name that is not a limiter.
