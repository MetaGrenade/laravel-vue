# Ignore list

A member can choose not to see another member in the forum. It is one way: the other person is not told, and it changes nothing for anyone else.

> **Status:** built: ignore and stop ignoring from a profile, a post, or **Settings → Privacy**, their posts collapse on a thread, and you are not notified when they reply to a thread you follow or mention you. **Not built:** see [below](#not-built).

## Using it

Signed in, open someone's profile and choose **Ignore**, or the same action on one of their posts. **Settings → Privacy** lists everyone you are ignoring, and each one can be removed.

You cannot ignore yourself. You cannot ignore a moderator (an administrator, an editor, or a moderator): their posts stay visible, including a warning. The same is true of someone you were already ignoring who is given one of those roles later. Their posts show again, and you are told when they reply or mention you, for as long as they hold the role. If the role is taken away, the earlier choice applies again. Ignoring someone and then ignoring them again changes nothing.

On a thread, each of their posts is replaced by "You are ignoring {name}", with **Show** for that post and **Stop ignoring**. Showing one post does not show the others. A guest, and anyone who has not ignored them, sees the posts as usual.

You are not emailed or notified in the site when someone you ignore replies to a thread you follow, or when they mention you. The mention is still stored on the post. Anyone else following that thread is told as before.

Deleting the account, on either side, drops the row.

## Not built

- **Hiding the threads they started** from a board, from search, or from the forum home. The home page is the same for everyone, so it cannot leave someone out for one viewer.
- **The list in a data export**, and an API for it.
- **A block that goes both ways**, or hiding them from the member directory. Their profile stays public.
- **Attachments and pictures in a post, and RSS.**

## Testing

`tests/Feature/Forum/ForumIgnoreTest.php`: ignoring someone collapses their post and not your own, a guest still sees it, the profile and the privacy page show the choice, stopping clears it, you cannot ignore yourself or a moderator, someone already ignored who is made a moderator is visible and still sends a reply notice, and a person who ignores the author is not told about a reply or a mention while someone who does not ignore them still is.
