# Email digests

A member can have community email gathered into one message a day, instead of an email for each update.

> **Status:** built: a daily digest for forum updates, blog activity, and direct messages. **Not built:** see [below](#not-built).

## Using it

**Settings → Notifications.** Forum updates, blog activity, and direct messages each have **Daily digest**. It is off until it is turned on. Email for that area has to be on as well. A notice in the site, and a browser alert, still arrive at once.

What would have been an email waits. At 08:00, in the site's timezone, one email goes out with what was waiting. Each area lists the latest twenty lines. A line past that is counted ("And 3 earlier updates") and is not written out. At most one hundred lines wait per area; anything after that is only in the site.

The words somebody else wrote are shown as words. A title that looks like a link does not become one. Each line links to the update, and the email links to the notification settings.

Turning the digest off does not throw away what is already waiting: the next morning email still carries it, and anything new after that is emailed at once. Turning email off for that area drops what was waiting. A banned account's waiting lines are dropped. An address that is not verified keeps them for fourteen days and then they are dropped. Deleting the account drops them.

Support, billing, security, and privacy emails are not part of this. They are still sent when they happen.

The schedule is `SendEmailDigests`, daily at 08:00. It needs the scheduler running, as the other daily jobs do, and a queue worker ([how it runs](#how-the-morning-job-runs)). If that job runs twice, each waiting line is still sent only once.

## How the morning job runs

`SendEmailDigests` sends nothing itself. It lists the members who have a line waiting, lowest id first and a hundred at a time (`EmailDigest::waitingMemberIds()`), and queues a `DeliverEmailDigests` job for each hundred. That job takes its members one by one: it claims the member's lines (a transaction that locks them), drops what the member no longer wants by email, queues one email for the rest, and deletes the lines it claimed in the same transaction. The email itself is sent from the `mail` queue, as before.

- **No job is long.** A member costs about 4 ms (9 ms when they have a hundred lines waiting), so a job takes under a second and the worker's 60 seconds are never in play. The single job this replaced was stopped at some thousands of members, with the lowest ids served and the highest never, every morning ([performance.md](performance.md)).
- **One member does not stop the others.** If a member's delivery fails (an error in one of their rows, a mail error raised when the email is queued), it is reported to the log like any other error, the member keeps their lines, and the job goes on to the next member. They are tried again the next morning, and reported again if it still fails; their lines are not lost, and (at most a hundred an area) do not grow without limit.
- **It can run twice.** A member's lines are deleted in the transaction that queues their email, so a second run of the same job, a retry after a worker was killed, or two schedulers finds nothing left for a member who was served, and still serves one who was not.
- **It needs a worker, as it did.** With a `database` or `redis` queue the jobs wait for a worker like any other ([redis.md](redis.md#the-queue)); with none running, no digest goes out. On the `sync` queue everything runs inside the scheduler at 08:00, one member after another.

## Not built

- **Choosing the hour**, or a digest more than once a day.
- **Support, billing, security, and privacy** in the digest.
- **A copy of the digest in the site.** The single updates are already there.
- **Other languages.**

## Testing

`tests/Feature/Notifications/EmailDigestTest.php`: with the choice off, a message is emailed at once; with it on, that email waits and the in-site notice does not, the morning job sends one email and then the line is gone, the latest twenty lines of an area are listed and an earlier one is only counted, a title that looks like a link is not one, a waiting line is dropped when email is off or the account is banned, an unverified address keeps a fresh line and drops one older than fourteen days, an address outside the site is emailed at once, a hundred waiting lines are the most an area holds, support email is not held, deleting the account drops the lines, a run that loses the claim sends nothing and a later run still sends the line, the setting is saved from the notification page, and the job is scheduled at 08:00.

`tests/Feature/Notifications/EmailDigestFanOutTest.php`: the morning job queues a job for each hundred waiting members, lowest id first, leaving out members with nothing waiting and counting one with several lines once, and sends nothing itself; a morning with nobody waiting queues nothing; the jobs use the default queue, and run in turn they serve every member once; a member whose delivery fails (an exception, or an `Error`) is reported and keeps their lines while the members after them are served, and the next morning serves them; a job serves only its own members and says how many it emailed; a job run again, and the morning job run twice, send nothing twice; the page of members is read in order, after the given id, up to the limit, each member once, and its `ORDER BY` is in the SQL.
