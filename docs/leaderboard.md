# Leaderboard

A public list of who has earned the most reputation, and the latest points that came from a post that is still public.

> **Status:** built: the top twenty members, and the latest twenty public events. **Not built:** see [below](#not-built).

## Using it

While the forum is on, **Leaderboard** is in the footer, on **Members**, and on the forum. Turning the forum off removes the page.

The top of the page is the members with the most reputation, up to twenty. Someone with none is left out, and so is a banned account. Two people with the same number are ordered by name, then by account, and each place has its own rank. A picture that is not an `http` or `https` address is dropped. The badges they have been given are shown with the name.

Below that, or beside it on a wide screen, are the latest twenty reputation events that are still public. Each line is the member, the kind of event, the points that were awarded then, a short piece of the post as plain text, and a link to that post. A reply that is held or deleted is left out, and so is a post in a thread that is not published, a post whose board is gone, and an event for a banned member. An event the site does not name is left out. The list is the same for everyone: ignoring someone, or following them, does not change it.

The points on a line are the points stored when it happened. The words are the current name of that kind of event, from the reputation settings. Today a public post is what earns points. An upvote and an accepted answer are named there and do not award points yet.

## Not built

- **Awarding points** for an upvote or for an accepted answer, and taking points back when a post is removed.
- **A longer list**, another period such as this week, or a feed that follows an ignore list.
- **Other languages.** The page uses translation keys. The event names in the reputation settings are English.

## Testing

`tests/Feature/Reputation/LeaderboardTest.php`: the ranking leaves out a banned account and anyone with no points, breaks a tie by name, drops a picture that is not a web address, keeps a badge, and stops at twenty. The activity feed is the newest public posts, leaves out a held reply, a deleted reply, an unpublished thread, a banned member and an event the site does not name, stays the same for someone who ignores the author, and is missing, along with its sitemap line, when the forum is off.
