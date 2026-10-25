# Solved answers

One reply that answers a thread. The thread carries that reply, or none.

> **Status:** built: the author or a moderator marks a reply, the thread page links to it, the board list filters by it, and the API sets it. **Not built:** see [below](#not-built).

## Using it

On a published thread, the person who started it, or a moderator, can mark a reply as the solution. A locked thread can still be solved: a lock stops new replies, it does not stop accepting an answer. An unpublished thread cannot, including one that automatic moderation is holding. Publish it first.

The opening post cannot be the solution, and deleting it does not make the next reply the opener. A reply that is held for review cannot. A reply from another thread cannot. Marking a different reply replaces the previous one. Marking the same reply again changes nothing. Clearing it leaves the thread unsolved.

The thread page shows a Solved label by the title and on the reply, and a link that opens the page that reply is on. The board list shows the label and filters with `?solved=yes` or `?solved=no`. Anything else in that parameter is ignored. The filter combines with the prefix filter and with search. A filtered page asks search engines not to index it. The forum home shows the label on the latest thread in a board, on trending threads and on the latest posts.

Deleting the reply clears the solution, including when a moderator takes down a member's replies in one go. The title stays.

## The API

`PUT /v1/forum/boards/{board}/threads/{thread}/solution` needs the `forum:write` scope. The body is `post_id`, the id of a reply, and the key has to be present. Null clears the solution. The same rules as the site apply. The thread payload includes `solution_post_id`, or null.

## Not built

- **An email, or reputation,** for the person whose reply was chosen, and a record of who marked it.
- **More than one solution** on a thread.
- **A filter on search or the home page**, and a sitemap entry for a filtered board page.
- **An ignore list, attachments and pictures in a post, and RSS.**

## Testing

`tests/Feature/Forum/ForumSolutionTest.php`: the author marks a reply and the thread, the board and the forum home show it on the right page, the opening post, a held reply and another thread's reply are refused, deleting the opening post leaves the next reply selectable, a stranger cannot mark one, a moderator can mark and clear one, a locked thread can be solved and an unpublished one cannot, deleting the reply clears it (including a delete that skips model events), the board filters solved and unsolved threads and ignores an unknown value, and the API sets and clears it.
