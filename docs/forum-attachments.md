# Pictures and files

A post can carry a picture or a file. It is shown with the post, and it can be opened only while the post itself can be seen.

> **Status:** built: up to five files on a new thread, a reply or an edited post; a picture is drawn again and shown; a PDF, a text file or a zip is downloaded. **Not built:** see [below](#not-built).

## Using it

On a new thread, a reply, or when editing a post, choose **Pictures and files**. A picture (JPEG, PNG, GIF or WebP) is decoded and saved again as a WebP, so the file that is shown is not the file that was uploaded. A PDF, a plain text file or a zip is kept as it is and offered as a download. Anything else is refused, including a picture that is really a document, and a file that is empty or larger than 4 MB.

The files sit on a private disk. A disk the web server would serve is refused, so a wrong `FORUM_ATTACHMENT_DISK` cannot publish them (see [private-uploads.md](private-uploads.md)). They sit under the post. While a reply is held for review, only its author and the moderators can open them. A thread that is held is not published, so only a moderator can open its files until it is approved. Deleting the post, the thread, the board it is in, the category that board is in, or the account removes the files. Taking down a member's replies removes the files on those replies; a thread of theirs is unpublished, and its files stay until the thread is deleted.

Editing a post can add files and can remove one that is already there.

## Deleting a board

The threads and replies of a board go with it by `ON DELETE CASCADE`, and a cascade runs no model event, so the hooks that delete the files of a thread or a reply never ran: the files of a board or a category that staff deleted stayed on the private disk for good. Now the board (and the category, for each of its boards) finds its files first, while the rows that name them still exist, and deletes them: in batches of 200, with one query for the whole board and not a list of reply ids (20,000 files took 1.9 seconds on SQLite, and the memory used did not grow). That takes in the replies that are held for review and the ones that were soft-deleted.

A file that cannot be deleted does not stop the rest. The disks are set not to throw, so a refusal (the directory is not writable by the web server) comes back as `false`; it is **reported with the name of the file**, in `storage/logs/laravel.log` and wherever errors are collected, and so is a row whose disk the site no longer has. The row goes either way, so the post shows no file that is not there.

### Files nobody names

The files that a board or a category left when it was deleted before this, and a file written for a reply that then could not be saved, are not named by any row. Once after upgrading, and again whenever you like:

```bash
php artisan metaforger:forum:attachments:prune --dry-run
php artisan metaforger:forum:attachments:prune
```

It looks in the attachment directory (`forum.attachments.directory`, on `FORUM_ATTACHMENT_DISK`) and deletes the files that no attachment row names. It says how many files and how much space, **counts only a file the disk really deleted**, warns by name about one that stays, and exits with a failure then. It leaves alone:

- a file younger than an hour, because a file is written before the row that names it;
- a file that is not named as this code names them: `{directory}/{post id}/{26 characters}.{pdf, txt, zip or webp}`, so a `.gitignore`, a file in a deeper directory or a file somebody else put there is never taken;
- a file that **any** row names, whatever disk the row says (two disk names may be one folder).

It is not scheduled. Nothing is lost that a post shows.

## Not built

- **A picture placed inside the sentence.** It is shown with the post.
- **The API**, and the list in a data export.
- **The public feed** does not include the file ([forum-rss.md](forum-rss.md)).
- **A schedule for the clean-up.** Files that nobody names are left until the command is run.

## Testing

`tests/Feature/Forum/ForumAttachmentTest.php`: a picture is shown and served as WebP, a PDF is downloaded, a document that claims to be a picture is refused, a public disk is refused, too many files and a file over the size are refused, an edit that would exceed five files does not publish, a failed replacement leaves the existing file, a held reply's file is not public, a held thread's file is only for a moderator, deleting the post removes the file, editing can remove one, taking down a member's replies removes theirs, and deleting an account removes files on replies in threads they started.

`tests/Feature/Forum/ForumBoardDeletionTest.php`: deleting a board or a category deletes the files of every kind of reply in it (published, in a held thread, held, soft-deleted) and not those of the next board, on more than one disk and in more than one batch; a file the disk refuses, and a disk the site does not have, are reported and do not stop the rest; a deletion that is called off leaves no row that names a deleted file. `tests/Feature/Forum/PruneForumAttachmentsTest.php`: the files a board left are deleted, a named file, a young file and anything not named as ours stay, the shape of a path, the 59 and 61 minute edge, a batch boundary, the disk and the directory that are set, a dry run, and a file that cannot be deleted is not counted.
