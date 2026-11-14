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

The notice in the site is written at once, so it is there when the next page is drawn. Everything else (the email, the browser alert) goes through the queue. That is two sends, and Laravel gives every send an id of its own. ("At once" is during the request for something sent to one person, such as a direct message or a support reply. For a post that many people follow it is when a worker picks the job up: see [Telling many people](#telling-many-people).)

`App\Support\Notifications\SplitDelivery` makes both parts carry **the same id**. It is used by `User::notifyThroughPreferences()`, by the support desk (`SupportTicketNotificationDispatcher`) and by the data export. Without it the alert pushed to the browser had an id that belonged to no stored row, and every button on it asked the server about something that did not exist.

One rule for anyone sending a notification this way: the closure given to `SplitDelivery` must return a notification of its own (a clone, or a new one) for each part, because the id is set on what it returns. Returning the object you also hand to another member would give them the same id, and the second row would not be stored.

## Telling many people

A new reply tells everyone who follows the thread, everyone who follows the author, and everyone the post names with `@`. A comment on a blog post tells everyone who follows its comments, and the post's author. Telling one member costs two queries (their notification settings, the notice in the site) and two queued sends (the email, the browser alert), so a loop in the request cost **4,002 queries and 4,000 queued sends at 2,000 subscribers**, 1.6 seconds and 26 MB, even with the database in memory and the queue faked (on a real database every query is a round trip as well). A member with ten thousand followers could not post.

The request now only works out **who** is to be told (ids, never a list of members) and queues `App\Jobs\NotifyMembers`, a hundred members to a job (`NotifyMembers::CHUNK`). The same request is **11 queries, 70 ms and 2 MB** at 2,000 subscribers, and queues 21 jobs. A worker then does what the request used to, member by member, with `User::notifyThroughPreferences()`, so everyone's choices count exactly as before.

- **Where it is used:** `ForumPublisher` (the thread's subscribers, the author's followers, the people mentioned), and `BlogCommentController` and `Api\V1\BlogCommentController` (through `Blog::commentRecipientIds()`). Anything new that tells a *group* uses `NotifyMembers::dispatchInChunks($ids, $notification, 'category')` and not a loop. One member is still told directly.
- **The settings are read once for the hundred**, not once for each (`with('notificationSettings')`). A member then costs one query, the notice.
- **One member who cannot be told does not stop the rest:** the exception is reported (`report()`), and the job goes on.
- **A job is not tried twice** (`$tries = 1`). A retry would tell the members it had already reached a second time, and a missing notice is better than a double one. A worker that is killed in the middle of a job loses at most the rest of its hundred, which is why the jobs are short.
- **The job waits for the commit** (`afterCommit()`), so a worker never looks for a post the request has not saved yet.
- **It needs the worker.** Without one, the notices and alerts for followers and subscribers wait in the queue like the emails ([deployment.md](deployment.md)). With `QUEUE_CONNECTION=sync` everything is done during the request, as it was before, and is as slow as it was.
- **Who is told is decided when the post is made, and who is deleted or has changed their mind by the time a worker runs is decided then:** a member who is gone is skipped, and a member's choices are read when they are told.

## What the bell does

The page is given the member's **unread** notifications, newest first, ten at most, with the count of all of them (`notifications` in `HandleInertiaRequests`). Each line has a `title`, an `excerpt`, a `url`, and `data`, which is what the notification stored. The `excerpt` of a forum notice is the start of the post as the member wrote it, on one line: `Don't` and `Q&A`, not the entities the post is stored with, and two paragraphs or two list items are two words (`App\Support\Text\PlainText`; the email says the same in its body).

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
| A big group becomes jobs of a hundred with every member in one; nobody to tell queues nothing; a job is not tried twice; each member is told by their own choices; the settings of a group are one query; one member who cannot be told does not stop the rest; a member who is gone is skipped | `tests/Feature/Notifications/NotifyMembersTest.php` |
| A reply, a comment on the web and a comment through the API queue the telling and do none of it; who is in the list (not the one posting, not the ignorer, not the banned, not twice) | `MemberFollowTest`, `BlogCommentNotificationsTest`, `BlogApiTest` |
| A push becomes a line (title, text, link, either shape, no id, not counted twice, at most ten) | `scripts/notifications.test.mjs`, run by `npm run bundle:test` |

The browser alert is only checked as far as the push: the header is not driven through a real websocket by any test.

## Trying it locally

The alert needs a Pusher-compatible server (`BROADCAST_CONNECTION=pusher`, `PUSHER_*` and the matching `VITE_PUSHER_*` in `.env`), and Vite rebuilt after changing them. Without `VITE_PUSHER_APP_KEY` the header never connects (`resources/js/lib/echo.ts`), and the bell only changes when a page is loaded. A notification that sends its alert through the queue needs a worker (see [redis.md](redis.md#the-queue)); the community ones push at once, whether a worker is running or not (`SendsBroadcastsSynchronously`).

## What it does not do

- **Mark a notice read when the bell is opened.** Nothing does, on purpose: a notice is read when it is clicked, marked or marked all.
- **List more than ten.** The bell says there are more; there is no page for the rest.
- **Replay what was missed.** An alert pushed while no browser was open is only the stored notice, shown the next time a page loads.
- **Keep an alert that has no stored notice.** A member who turned *In-app* off but kept *Browser alerts* is pushed an alert and nothing is stored. It is in the bell until the next response that carries the notifications list (the next page, or any button in the bell, which then lists only what is stored), and its buttons change nothing on the server.
