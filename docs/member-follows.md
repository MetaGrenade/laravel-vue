# Following

A member can follow another member. It is one way: the other person is not told, and it changes nothing for anyone else.

> **Status:** built: follow and stop following from a profile or from **Settings → Privacy**, a Following page of their public posts, and a notice when they post. **Not built:** see [below](#not-built).

## Using it

Signed in, while the forum is on, open someone's profile and choose **Follow**. Your own profile links to **Following**. The account menu does too. **Settings → Privacy** lists everyone you follow, and each one can be removed.

You cannot follow yourself. You cannot follow a banned member. You can follow a moderator. Following someone and then following them again changes nothing.

**Following** lists their public forum posts, newest first: a new thread and a reply, in a thread that is published. A post that is held for review is not listed, and neither is a post in a thread that is not published. A member you are ignoring stays on the list of people, but their posts stay off the page, the same as a member who has since been banned.

You are told, in the site and by email when you allow it, when someone you follow posts. The email can be a daily digest ([email-digests.md](email-digests.md)). The link opens the page that post is on, which is a later page once the thread has more than ten posts. You are not told again when that post already tells you another way: a reply in a thread you follow, or a post that mentions you. You are not told when you ignore them. A post that is held tells nobody until a moderator approves it, and then it tells them as a normal post would.

Deleting the account, on either side, drops the row.

## Not built

- **Blog comments** on the Following page.
- **The list in a data export**, and an API for it.
- **A follow that goes both ways.**
- **The public feed** lists every published thread, not only people you follow ([forum-rss.md](forum-rss.md)).

## Testing

`tests/Feature/Forum/MemberFollowTest.php`: following someone and then stopping, a moderator can be followed, the feed shows a public post and leaves out a held post, an unpublished thread, someone you ignore and someone who is banned, a follower is told unless they already heard or they ignore the author, a reply past the first ten posts opens on its page, you cannot follow yourself or a banned member, and the choice and the page hide when the forum is off.
