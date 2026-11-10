# Live updates (Pusher)

How the site pushes a notification to an open browser tab, and a new reply to a forum thread that someone is reading, without a reload. This page is the setup; [Notifications and the bell](notifications.md) says what the bell does with what arrives.

> **Status:** built. The code is covered by tests with the log broadcaster. **Not run against a real Pusher app** for this page, so check the steps below against your own account the first time.

## What is live

- **The bell.** A signed-in member's browser listens on its own private channel, `App.Models.User.{id}`, for notifications sent through the `broadcast` channel ("Browser alerts" in Settings → Notifications).
- **A forum thread.** A browser that has a thread open joins the presence channel `forum.threads.{id}`. The thread shows who else is reading it (up to three names, then "and 2 more"), and when somebody replies a notice appears with the author, an excerpt and a link to the reply. The reader's own replies, and those of people they have ignored ([Ignoring members](forum-ignores.md)), are not announced. A thread that is not published can only be joined by its author and by staff.

Both channels are authorised by `routes/channels.php` through `/broadcasting/auth`, which a signed-in member's browser calls with the usual CSRF token.

Nothing else depends on it. With no Pusher account the site logs what it would have pushed (`BROADCAST_CONNECTION=log`) and every page still works: the notification is in the bell the next time the page is drawn, and a new reply is there on the next load.

## Setting it up

1. **Make a Pusher app.** In the [Pusher Channels dashboard](https://dashboard.pusher.com/) create an app in the region closest to your visitors, and copy its **App ID**, **Key**, **Secret** and **Cluster**. Any service that speaks the Pusher protocol (a self-hosted Soketi, for one) works the same way.

2. **Give the server the credentials.** In `.env`:

   ```ini
   BROADCAST_CONNECTION=pusher
   PUSHER_APP_ID=your-app-id
   PUSHER_APP_KEY=your-app-key
   PUSHER_APP_SECRET=your-app-secret
   PUSHER_APP_CLUSTER=mt1        # the cluster of your app
   ```

   For a compatible service of your own, also set `PUSHER_HOST`, `PUSHER_PORT`, `PUSHER_SCHEME` (`http` or `https`) and `PUSHER_FORCE_TLS`; `PUSHER_VERIFY_SSL=false` is for a self-signed certificate on a development machine only. `config/broadcasting.php` turns those text values into the port, the TLS flag and the certificate check the client needs. With all three of the id, key and secret set, the site uses Pusher even when `BROADCAST_CONNECTION` is left out. To keep a staging site from pushing to real people, leave `BROADCAST_CONNECTION` out (or set it to `log`) and set `BROADCAST_FORCE_LOG=true`; with `BROADCAST_CONNECTION=pusher` written out, as `.env.example` has it, the credentials win and the flag does nothing. Every key is in the [configuration reference](configuration.md#realtime).

3. **Give the browser the same key.** The page cannot read `.env`, so the browser's copy is made when the front end is built. `.env.example` already has these lines, which copy the server's values:

   ```ini
   VITE_BROADCAST_DRIVER="${BROADCAST_CONNECTION}"
   VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
   VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
   VITE_PUSHER_HOST="${PUSHER_HOST}"
   VITE_PUSHER_PORT="${PUSHER_PORT}"
   VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
   VITE_PUSHER_FORCE_TLS="${PUSHER_FORCE_TLS}"
   ```

   An empty host or port falls back to the cluster's host and the default port for the scheme (443 for `https`, 80 for `http`). Only the **key** is public; the secret never goes to the browser.

4. **Build again.** A `VITE_*` value is written into the JavaScript by `npm run build`, so changing it means building again (in Docker, building the image again: [Running in Docker](docker.md)). While developing, restart `npm run dev` and `php artisan serve` after editing `.env`.

5. **Run a queue worker.** The email and the browser alert of a notification are sent from the queue, so a worker has to be running or nothing is pushed ([Running on Redis](redis.md#the-queue)).

6. **On a staging site, keep it from pushing.** A copy of the site that has the production `.env` (to test against real data) has the production Pusher keys too, and would push to real people's browsers. Set `BROADCAST_FORCE_LOG=true`: it wins over `BROADCAST_CONNECTION` and the keys, and the site logs what it would have pushed. `php artisan config:show broadcasting.default` should then say `log`. (Before, it was ignored next to `BROADCAST_CONNECTION=pusher`, which `.env.example` sets: see [upgrading.md](upgrading.md).)
## How the browser connects

`resources/js/lib/echo.ts` loads Laravel Echo and the Pusher client only for a signed-in member, and only when the build has a key (`VITE_PUSHER_APP_KEY`). A guest, and a build without a key, never download them. The content security policy lets the page connect to `*.pusher.com`, and to the host in `PUSHER_HOST` (with its port) when you set one, so a service of your own needs no change to `config/security.php` ([Security headers](security-headers.md)).

## When nothing arrives

- **The server is still on `log`.** `php artisan tinker --execute="echo config('broadcasting.default');"` should say `pusher`. If it says `log`, one of the three server credentials is missing, or `BROADCAST_FORCE_LOG` is `true` (it wins over everything else).
- **The browser has no key.** Open the page's source and look for the key in the built script, or check that you built after setting the `VITE_*` lines. This is the usual cause after a change on a server whose files were built earlier.
- **No worker.** The notification is stored, and the bell shows it on the next page, but nothing is pushed until a worker sends the `broadcast` part.
- **`/broadcasting/auth` answers 403 or 419.** The member is not signed in any more (403), or the page was open so long that its token expired (419). A reload fixes both.
- **A proxy that does not pass WebSockets.** The browser talks to Pusher's own servers (or to the host you set) and not to your web server, so this only matters when `PUSHER_HOST` points at a service behind your proxy.
