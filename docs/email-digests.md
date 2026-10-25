# Email digests

A member can have community email gathered into one message a day, instead of an email for each update.

> **Status:** built: a daily digest for forum updates, blog activity, and direct messages. **Not built:** see [below](#not-built).

## Using it

**Settings → Notifications.** Forum updates, blog activity, and direct messages each have **Daily digest**. It is off until it is turned on. Email for that area has to be on as well. A notice in the site, and a browser alert, still arrive at once.

What would have been an email waits. At 08:00, in the site's timezone, one email goes out with what was waiting. Each area lists the latest twenty lines. A line past that is counted ("And 3 earlier updates") and is not written out. At most one hundred lines wait per area; anything after that is only in the site.

The words somebody else wrote are shown as words. A title that looks like a link does not become one. Each line links to the update, and the email links to the notification settings.

Turning the digest off does not throw away what is already waiting: the next morning email still carries it, and anything new after that is emailed at once. Turning email off for that area drops what was waiting. A banned account's waiting lines are dropped. An address that is not verified keeps them for fourteen days and then they are dropped. Deleting the account drops them.

Support, billing, security, and privacy emails are not part of this. They are still sent when they happen.

The schedule is `SendEmailDigests`, daily at 08:00. It needs the scheduler running, as the other daily jobs do.

## Not built

- **Choosing the hour**, or a digest more than once a day.
- **Support, billing, security, and privacy** in the digest.
- **A copy of the digest in the site.** The single updates are already there.
- **Other languages.**

## Testing

`tests/Feature/Notifications/EmailDigestTest.php`: with the choice off, a message is emailed at once; with it on, that email waits and the in-site notice does not, the morning job sends one email and then the line is gone, the latest twenty lines of an area are listed and an earlier one is only counted, a title that looks like a link is not one, a waiting line is dropped when email is off or the account is banned, an unverified address keeps a fresh line and drops one older than fourteen days, an address outside the site is emailed at once, a hundred waiting lines are the most an area holds, support email is not held, deleting the account drops the lines, the setting is saved from the notification page, and the job is scheduled at 08:00.
