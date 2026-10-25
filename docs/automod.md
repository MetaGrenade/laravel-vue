# Automatic moderation of the forum

An administrator sets **rules**. Every new thread and reply a member posts is checked against them. A post that breaks a rule is either **held** (kept out of sight until a moderator approves it) or **refused** (not kept, and the member is told it looks like spam). Moderators work through what was held in a **review queue**.

> **Status:** built: the four kinds of rule, holding and refusing, the review queue (single and bulk), the rules screen with a box to try a text, the hidden-field trap on the forum's forms, and the API's answers. **Not built:** see [below](#not-built). Clearing out everything a spammer posted, and banning a wave of accounts together with their posts, is the next slice.

## What is checked, and who

- **Everything a member types into a new thread or reply**, on the site and through the API. A thread's title is checked as well as what it says.
- **Everybody who is not staff.** `admin`, `editor` and `moderator` are never checked.
- **Each rule can be limited to new accounts** ("accounts under 14 days old"), which is the usual way to keep a link rule from bothering people who have been members for years.

Editing a post after it is out is **not** checked (see [not built](#not-built)).

## The rules

**Admin → Forums → Automod rules** (`forums.acp.automod`, administrators have it). Each rule has a name, a kind, what it needs, what it does, and who it is for.

| Kind | Catches | Needs |
|---|---|---|
| **Words and phrases** | A phrase anywhere in the post or title, in any capitals, with the markup left out (`<b>cheap</b> watches` is "cheap watches"). | The phrases, one on each line (blank lines are left out; up to 500). **Whole words only** keeps "ass" from catching "class". |
| **Links** | A post with **more links** than a number, and/or **any link to a domain**. | A number (0 allows none) and/or domains, one on each line. A domain catches its subdomains (`bad.example` catches `shop.bad.example`, but not `notbad.example`); `www.`, a scheme, a path or a full stop at the end of the name (`bad.example.` is `bad.example`, and a link to it works) in what you type, or in a link, is ignored. A link is counted once however many times it appears, and whether it is in an `href` or typed. |
| **The same text again** | The member posting the same words again: capitals and spacing do not matter. | How many earlier copies (1 catches the second post) and in the last how many minutes. Their held posts count. |
| **Too many posts** | A member who has already posted a number of times in the last minutes. | How many posts and how many minutes. Held posts count, so a wave of spam from one account is caught from its fourth post. |

**What a rule does:** *held* (the default) or *refused*. If a post breaks several rules, a rule that refuses wins; otherwise the first rule that holds it (in the order they were made) is the one that does. **Only that rule is counted** (`Caught` on the screen, with when it last did).

**Try a text** at the top of the screen shows which rules would catch what you paste, without posting or counting anything. It does not try the two rules about how a member has been posting, since they need a member.

Changes to the rules are in the [audit log](audit-log.md) as they were and as they became.

## What happens to a held post

- **It is kept**, but is in nobody's lists, counts, searches or feeds. For its author a held **reply** is on the thread page, marked **Awaiting review**, and they are told "Your reply has been sent for review." Until it is out it offers no edit, delete, report or history to anybody, moderators included: a moderator approves or rejects it in the review queue. A held **thread** is not published, so only moderators can open it; its author is sent to the board with "Your thread has been sent for review."
- **Nothing has happened that a post makes happen.** Nobody is notified (the people following the thread, the people it mentions), no reputation is earned, the thread is not bumped, and the live page is not told. A spammer's post does not reach anybody who would be bothered by it.
- **Moderators see it** in the queue, and held replies are marked on the thread page with which rule held them and why.
- **Approving it** posts it as if it had never been held: everything above happens now, once. Approving twice, or two moderators at the same moment, does it once.
- **Rejecting it** removes it, and the author is not told (it is usually spam).
- The rule that held it can be deleted afterwards: the reason it gave stays with the post.

A refused post is not kept. The member sees "This looks like spam, so it was not posted. If you think that is a mistake, please contact a moderator." on the form, and what they wrote is still in the editor.

## The review queue

**Admin → Forums → Review queue** (`forums.acp.review`; moderators have it). Held **replies** and held **threads** are in two tabs, the one that has waited longest first. Each shows what was posted, where, who wrote it (how old their account is, how many approved posts they have), which rule held it and why, and:

- **Approve** and **Reject** (one at a time, or tick several: **Approve** and **Reject** for the ticked ones);
- **Warn or ban** goes to the author's [moderation page](moderation.md) with the post attached, so the warning says what it was about. It is shown only to somebody who may warn or ban, and never for your own post.

## The hidden field

The forum's reply form and new-thread form carry a field that people never see, which a script fills in. A post with it filled in is refused, as the blog's comment form already did. The API is not asked for it: a client with a key is not a form.

## The API

- A reply or thread that is **held** answers **`202`** (instead of `201`) with what was made, and `held: true` on the reply (and on the thread). Nothing is there for anybody else yet, so do not show it as posted.
- One that is **refused** answers `422` with the message under `body`.
- A held thread is left out of the listings, and `latest_post` is never a held reply.

## Permissions

| Permission | |
|---|---|
| `forums.acp.review` | See the review queue, approve and reject. The `moderator` role has it. |
| `forums.acp.automod` | Make, change, switch off and delete the rules. Administrators only, unless you give it. |

They are separate from `forums.acp.create`, `edit` and `delete`, which guard the boards and categories. Run `php artisan db:seed --class=RolePermissionSeeder` to create them and give `moderator` the review queue (and `forums.acp.view`, which it did not have: the page of reports and the forum list were closed to moderators).

## Setting it up

1. `php artisan migrate` (the rules, and three columns on forum threads and replies).
2. `php artisan db:seed --class=RolePermissionSeeder`.
3. Add a rule or two. Reasonable ones to start with: *links from accounts under 14 days old* (more than 2 links, held), *the same text again* (1 copy in 60 minutes, held), *too many posts* (5 in 10 minutes, held), and a *words* rule of whatever your forum is plagued by.

Nothing is held until there is a rule, so an upgrade holds nothing by itself (the hidden field is the one thing checked from the start).

## How it fits together

- `App\Support\Moderation\Automod` is the rules: `check()` for a member's post, `test()` for the box.
- `App\Support\Forum\ForumSubmissions` is the one place a thread or reply is created (the site and the API both use it): rules asked, then posted, held or refused.
- `App\Support\Forum\ForumPublisher` is what follows a post that is out: the bump, the read marker, mentions, followers, the live page, reputation. It used to be written out in each controller; it is here so that a post a moderator approves later gets exactly the same.
- `App\Support\Forum\ForumReview` approves and rejects, under a lock.
- `ForumPost` has a global scope (`ForumPost::VISIBLE`) that leaves held replies out of **every** query, so nothing that lists, counts or searches replies has to remember them. What needs them asks `withoutGlobalScope(ForumPost::VISIBLE)`.
- A held thread is simply an unpublished one with `held_at` set, so every list that already hides unpublished threads hides it. Publishing a thread by any means ends the hold.

## Not built

- **Checking edits.** A post is checked when it is made, not when it is changed: somebody can post something harmless and edit a link in afterwards. The edit history keeps what it was.
- **Blog comments, support tickets and profiles.** Only the forum. (Blog comments have the older honeypot and token guard.)
- **Telling the author** a held post was approved or rejected.
- **Rules by pattern** (regular expressions), by IP address or by email domain, and **per-board rules**.
- **Trust that builds up** (a member whose posts are always approved being checked less).
- **Automatic bans.** A rule holds or refuses; a person decides about the member.

## Testing

`tests/Feature/Forum/AutomodTest.php`: every kind of rule, who is checked, holding and refusing, what a held post does and does not do (and that approving does it, once), who sees a held reply, the thread flow, the API, and the hidden field. `tests/Feature/Admin/ForumReviewTest.php` and `AutomodRulesTest.php`: the queue, its permissions, approving and rejecting (one, several, twice), the rules screens, what is stored, the box and the audit log. `tests/Feature/Database/AutomodMigrationTest.php`: the schema on every database CI uses.
