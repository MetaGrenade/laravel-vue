# Thread prefixes

A short label on a thread, chosen from the ones a board offers. One prefix per thread.

> **Status:** built: staff define prefixes, a board can require one on a new thread, the board list filters by one, and the site and the API set it. **Not built:** see [below](#not-built).

## Using it

**Admin → Forums → Thread prefixes** (anyone who can see the forums admin) lists the prefixes. Making, editing and deleting one uses the same permissions as making, editing and deleting a board (`forums.acp.create`, `edit`, `delete`). A moderator can look, and cannot change the list.

A prefix has a name (40 characters), an address of its own, a colour from a fixed set (slate, sky, green, amber, rose, violet) and the boards that offer it. It can be offered nowhere. The order is the order the form shows them.

On a board's edit page, **Require a prefix** means a new thread has to pick one. That only applies when the board actually offers at least one. A board with the box ticked and no prefixes still accepts a thread with none, and a thread that already exists is not forced to gain one.

The person who started a published, unlocked thread can set or clear the prefix, and so can a moderator. Editing can always clear it, even on a board that requires one for a new thread. The prefix has to be one that board offers.

The board page shows the label in front of the title, and a row of labels that filters the list (`?prefix=help`). A filter that names nothing the board offers is ignored. A filtered page asks search engines not to index it. The forum home shows the label on the latest thread in a board, on trending threads and on the latest posts.

Taking a prefix off a board takes it off that board's threads. The title stays. Deleting a prefix does the same everywhere it was used.

## The API

`prefix` on a thread is the prefix's address (its slug), or null. The site's forms send the id instead. A slug made only of digits is still a slug, so it is never taken to mean the prefix with that id.

- Creating a thread sends it when the board offers one, and must send it when the board requires one.
- Updating a thread omits it to leave the current one, and sends null to clear it.
- A prefix another board offers is refused.

The thread payload includes `prefix` as `{ id, name, slug, color }` or null.

## Not built

- **Several labels on one thread**, or a label a member types themselves.
- **A picture placed inside the sentence, and RSS.** A solved reply is separate ([forum-solutions.md](forum-solutions.md)). Ignoring a member is separate ([forum-ignores.md](forum-ignores.md)). A file on a post is separate ([forum-attachments.md](forum-attachments.md)).
- **Moving a thread to another board.** A prefix that the new board does not offer stays until the prefix's boards are saved again.
- **A search result or a sitemap entry** for a filtered board page.

## Testing

`tests/Feature/Forum/ForumPrefixTest.php`: staff create a prefix and attach a board, a moderator cannot, a board can require one, a new thread must pick one that board offers, the board list shows it and filters by it, the forum home shows it, the author and a moderator can change it and a locked thread's author cannot, clearing it on edit is allowed, deleting a prefix or taking a board off it clears the thread, the API accepts a slug and refuses one from another board, and a slug of digits selects that slug rather than the prefix whose id it matches.
