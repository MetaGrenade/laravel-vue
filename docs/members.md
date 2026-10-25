# Members

A public page for each account, and a directory of them.

> **Status:** built: the directory, a profile, links from a forum post and a blog comment, the sitemap, and following a member ([member-follows.md](member-follows.md)). **Not built:** see [below](#not-built).

## Using it

**Members** in the header opens the directory. It lists everyone who is not banned, newest first, and can be sorted by name or reputation or searched by name. A card shows the picture, the name, when they joined, their reputation and the start of their bio.

A profile shows the same, plus the bio, the links they added, their badges, and (while the forum is on) their signature, how many public threads they have started, how many replies they have made in other people's threads, and the latest few of each. While the forum is on, a signed-in member can ignore them or follow them from the profile ([forum-ignores.md](forum-ignores.md), [member-follows.md](member-follows.md)). While the blog is on it also says how many of their comments are on published posts. **Settings → Profile** links to the person's own page. A name on a forum post and on a blog comment opens the profile.

The address is the account's id (`/members/42`), not the name. A name can change, and it can contain characters that do not belong in an address, so an old link keeps working.

## What is left out

- **A banned account** is not in the directory, not in the sitemap, and has no profile (the address answers "not found"). That is what keeps a spam bio from staying up. Their name can still be on a post until a moderator [takes down what they posted](moderation.md#taking-down-what-a-member-posted).
- **The email address**, the time zone, the language, and when they were last here. The directory searches names only, so typing an email address finds nobody.
- **Anything that is not public.** An unpublished or held thread, a deleted or held reply, a reply in a thread they started (it belongs to that thread), a comment that is not approved, and a comment on a post that is not published. A link or a picture that is not an `http` or `https` address, or that hides a login in the address, is dropped.
- **The forum, when it is switched off.** No signature, no threads and no replies. The same for the blog's comment count.

A search, and any page after the first, asks search engines not to index it. The directory itself and each profile are in the sitemap.

## Not built

- **Direct messages.** Following a member is built ([member-follows.md](member-follows.md)).
- **Last seen.**
- **Their product reviews**, support tickets, orders and anything else that is not a public post.
- **Editing the profile from this page.** That stays in Settings → Profile.

## Testing

`tests/Feature/Members/MemberDirectoryTest.php`: who the directory lists and in what order, that a search matches a name and not an email, that a banned account is absent and has no page, what a profile shows and what it refuses (taken-down posts, a script in a bio, a picture or a link that is not a web address), that switching the forum off removes that part, and that the sitemap lists a profile and not a banned account.
