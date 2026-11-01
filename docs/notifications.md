# Notifications and the bell

How a notification reaches a member, what the bell in the header does with it, and what the tests hold in place.

> **Status:** built: the preferences, the notice in the site, the email, the browser alert, and the bell with its three buttons. Not built: a page that lists every notification, ever (the bell shows the ten newest unread ones), and marking a notice read when the bell is opened. See [What it does not do](#what-it-does-not-do).

## The three channels

| Channel | What the member sees | Called in Settings |
|---|---|---|
| `database` | A line in the bell, stored until it is read or dismissed | In-app |
| `mail` | An email, to a verified address only | Email |
| `broadcast` | The same line, pushed to an open browser so it appears without a reload | Browser alerts |

Each category (blogs, forums, messages, support, privacy) has its own choice of channels in **Settings → Notifications**; `config/notification-preferences.php` lists them. `User::notifyThroughPreferences()` is what community notifications are sent with; it works out the channels from the member's choices.

## One notification, sent in two parts

The notice in the site is written during the request, so it is there when the next page is drawn. Everything else (the email, the browser alert) goes through the queue. That is two sends, and Laravel gives every send an id of its own.

`App\Support\Notifications\SplitDelivery` makes both parts carry **the same id**. It is used by `User::notifyThroughPreferences()`, by the support desk (`SupportTicketNotificationDispatcher`) and by the data export. Without it the alert pushed to the browser had an id that belonged to no stored row, and every button on it asked the server about something that did not exist.

One rule for anyone sending a notification this way: the closure given to `SplitDelivery` must return a notification of its own (a clone, or a new one) for each part, because the id is set on what it returns. Returning the object you also hand to another member would give them the same id, and the second row would not be stored.

## What the bell does

The page is given the member's **unread** notifications, newest first, ten at most, with the count of all of them (`notifications` in `HandleInertiaRequests`). Each line has a `title`, an `excerpt`, a `url`, and `data`, which is what the notification stored.

When an alert is pushed over the websocket, the header adds it at the top without a reload. What Laravel pushes is **not** the shape the page was given. The notification's own fields sit at the top level, next to the id and the type:

```json
{ "title": "New reply in \"Something to Test\"", "excerpt": "Ada: it works", "url": "/forum/…", "thread_title": "Something to Test", "id": "6b0f2c1e-…", "type": "App\\Notifications\\ForumThreadUpdated" }
```

`resources/js/lib/notifications.ts` turns that into a line of the list (and understands the stored shape too). An alert whose id is already in the list is not counted twice. The title is chosen as the server chooses it: `title`, then `thread_title`, then the word "Notification", which means the notification stored neither.

| Button | Request | Does |
|---|---|---|
| The line | `POST notifications/{id}/read`, then visits its `url` | Marks it read, then opens it |
| Mark read | `POST notifications/{id}/read` | Marks it read |
| Dismiss | `DELETE notifications/{id}` | Deletes it |
| Mark all read | `POST notifications/read-all` | Marks every unread one read |

An id that is not one of the member's stored notifications is **not an error**: the request changes nothing and the page comes back. That happens for an alert that was only pushed (the member turned the notice in the site off but kept the browser alert), for one dismissed in another tab, and for somebody else's id, which is answered exactly like one that does not exist. So is text that is not shaped like an id at all: the column is a `uuid`, PostgreSQL refuses to compare it with anything else (a 500), so the controller only looks up what is UUID-shaped (`NotificationBellTest` runs this on every database CI uses). It used to answer 404, and the bell showed that as an error dialog.

## What the tests hold in place

| Test | Where |
|---|---|
| The two parts share one id; every member gets an id of their own; the id in the browser alert is the stored row's, and its fields are at the top of the push; the three buttons, for a notice that is stored, not stored and somebody else's | `tests/Feature/Notifications/NotificationBellTest.php` |
| The same for the support desk and the data export | `SupportTicketNotificationTest`, `DataExportTest` |
| A push becomes a line (title, text, link, either shape, no id, not counted twice, at most ten) | `scripts/notifications.test.mjs`, run by `npm run bundle:test` |

The browser alert is only checked as far as the push: the header is not driven through a real websocket by any test.

## Trying it locally

The alert needs a Pusher-compatible server (`BROADCAST_CONNECTION=pusher`, `PUSHER_*` and the matching `VITE_PUSHER_*` in `.env`), and Vite rebuilt after changing them. Without `VITE_PUSHER_APP_KEY` the header never connects (`resources/js/lib/echo.ts`), and the bell only changes when a page is loaded. A notification that sends its alert through the queue needs a worker (see [redis.md](redis.md#the-queue)); the community ones push at once, whether a worker is running or not (`SendsBroadcastsSynchronously`).

## What it does not do

- **Mark a notice read when the bell is opened.** Nothing does, on purpose: a notice is read when it is clicked, marked or marked all.
- **List more than ten.** The bell says there are more; there is no page for the rest.
- **Replay what was missed.** An alert pushed while no browser was open is only the stored notice, shown the next time a page loads.
- **Keep an alert that has no stored notice.** A member who turned *In-app* off but kept *Browser alerts* is pushed an alert and nothing is stored. It is in the bell until the next response that carries the notifications list (the next page, or any button in the bell, which then lists only what is stored), and its buttons change nothing on the server.
