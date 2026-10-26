# Forum RSS

The forum has a public feed of its published threads, in the same shape as the blog feed.

> **Status:** built: one feed for the forum, and one for each board. **Not built:** see [below](#not-built).

## Using it

On the forum, and on a board, choose **Subscribe via RSS**. The address is also in the page header while the forum is on.

The forum feed lists the latest twenty published threads, newest activity first. A board's feed lists only that board. A thread that is not published, including one held for review, is left out. A locked thread stays. Pinning a thread does not move it up the feed.

Each line is the thread: its title, with the prefix in front when it has one, a short excerpt, the board, and a link to the thread. The words somebody else wrote are shown as words. The time on a line is when it was last posted in, not when a held reply was saved.

The feed is the same for everyone. It does not hide a member you ignore, and it is not limited to people you follow. A picture or a file on a post is not in the feed.

Turning the forum off removes the feed.

## Not built

- **A line for each reply**, or a feed of one thread.
- **A private feed** that follows your ignore list, or only people you follow.
- **Choosing how many lines**, or a format other than the one the blog uses.
- **Other languages.**

## Testing

`tests/Feature/Forum/ForumFeedTest.php`: the forum feed is Atom, lists a published thread by its latest activity, puts the prefix in the title, keeps the board and the excerpt as text, leaves out a thread that is not published, a board feed is only that board and stops at twenty lines, and both feeds are missing when the forum is off.
