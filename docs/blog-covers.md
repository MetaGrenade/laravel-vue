# Blog covers

A post can have a cover picture. It is shown on the post, and it is a public image.

> **Status:** built: a new cover, or a cover that replaces one, is decoded and saved again as WebP at three widths, the files go when the post does (or the member who wrote it), and two commands draw older covers again and clear the files nobody names. **Not built:** see [below](#not-built).

## Using it

On a new post, or when editing one, choose a cover. A JPEG, PNG, GIF or WebP is decoded and saved again as WebP on the public disk, so the file that is shown is not the file that was uploaded. Its longest side is the same size the shop uses for a large product picture, and it is saved again at 960 and 480 pixels wide for the lists and for phones ([images.md](images.md)). A smaller picture is not enlarged. Anything that is not a picture is refused, including a file that is only named like one, and a picture with more pixels than the shop allows is refused before it is decoded. A cover larger than 5 MB is refused as well.

Replacing a cover saves the new picture first. The previous cover, every size of it, is removed only after that succeeds, so a cover that is refused leaves the one already on the post.

A post without a cover shows a placeholder in the list, and nothing on the post.

## Deleting a post

Deleting a post, in **Admin → Blog** or by any other way a post is deleted, removes every size of its cover from the public disk, and every cover that its revisions name (they go with the post). Deleting the **member who wrote** a post deletes their posts with the account, and their covers go with them ([account-deletion.md](account-deletion.md)).

Two rules hold the deletion to what it should take:

- **Only a file of the covers directory.** The path of a cover is a value in a row, and nothing but its shape says it is a cover: a file directly in `blog-covers/`, named by letters, digits, dots, dashes and underscores, not starting with a dot. That is the shape of the names this site makes, and of the ones the first version made (`blog-covers/{random}.jpg`). A row that names anything else, such as a product picture, is not a reason to delete that file, whichever way the row got its value.
- **Not a file that another post names.** Nothing in the site gives two posts one file, but if a row says so, the file stays until the last of them goes.

A disk that cannot be reached does not undo a deletion that has happened: the error is reported, and the post is gone. The same holds for a file the disk refuses to delete without saying why (the public disk is set not to throw, so it answers `false`): it is reported with the name of the cover, and the post is gone. The prune command below finds what stays.

## Covers already on the site

A cover uploaded before the sizes were built has one file, and is shown at that one size. `php artisan metaforger:blog:covers:upgrade` draws them again ([images.md](images.md#covers-already-on-the-site)); `--dry-run` says what it would do.

### Files nobody names

Before this, deleting a post, or the member who wrote it, left the files of its cover on the disk for good, and a cover that was written and then not saved (the request failed in between) did too. Once, after upgrading, and again whenever you like:

```bash
php artisan metaforger:blog:covers:prune --dry-run
php artisan metaforger:blog:covers:prune
```

It looks at the files directly in `blog-covers/` and deletes those that no post and no revision names, with every size of a cover counted as named when the cover is. It says how many files and how much space were deleted, and **only counts a file the disk really deleted**: the public disk is set not to throw, so a file it cannot delete (the directory is not writable by the web server's user) is reported to the command as a refusal, not an error. Each such file gets a warning with its name, is left out of the totals, and the command ends with a message that says how many are still there and **exits with a failure**, so a script or a cron line notices. It leaves alone:

- a file younger than an hour, because a cover is written before the post that names it, and a save may be in the middle;
- a file that is not named like a cover, such as `.gitignore`, and everything outside `blog-covers/`.

It is not scheduled. The files it finds are public pictures on a disk you may also keep other things on, so it does nothing until you run it. Nothing is lost that a post shows: a file that any post or revision names is never listed.

## Not built

- **A picture inside the post.** Only the cover is made in several sizes.
- **A schedule for the clean-up.** Files that nobody names are left until the command is run.
- **A revision keeps the cover it was made with.** Replacing a cover removes the old files, so restoring a revision from before the replacement gives a post whose cover file is gone.

## Testing

`tests/Feature/Blog/BlogCoverTest.php`: a cover is stored as WebP, fitted to the longest side, and shown on the post; a note in the file does not survive; a text file and a picture with too many pixels are refused and leave nothing behind; a refused replacement keeps the existing file; a successful replacement removes the previous file.

`tests/Feature/Blog/BlogCoverSizesTest.php`: the three widths, what the pages send, and the upgrade command ([images.md](images.md#testing)).

`tests/Feature/Blog/BlogCoverDeletionTest.php`: deleting a post takes every size of its cover and the covers its revisions name and no other post's; a cover that is one file goes too; a file another post or revision names stays; seven kinds of path that are not a cover's (a product download, above the directory, inside something else, a dotfile, a name with a space, a subdirectory, the directory itself) are not deleted whichever row names them; an unreachable disk, and a file the disk refuses to delete (it says `false` or throws), do not undo the deletion and are reported; the command counts only the files that went and fails when one stays; more than one batch of paths is looked at for sharing; the clean-up command takes the files nobody names (a size whose large file is gone, a file of the first version) and leaves what a post or revision names, files under an hour old, other directories and what is not named like a cover. `tests/Feature/Privacy/DeletedMemberTest.php` deletes a member three ways and counts the covers of their posts.
