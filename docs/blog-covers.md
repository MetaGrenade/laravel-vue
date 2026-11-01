# Blog covers

A post can have a cover picture. It is shown on the post, and it is a public image.

> **Status:** built: a new cover, or a cover that replaces one, is decoded and saved again as WebP at three widths, and a command draws older covers again. **Not built:** see [below](#not-built).

## Using it

On a new post, or when editing one, choose a cover. A JPEG, PNG, GIF or WebP is decoded and saved again as WebP on the public disk, so the file that is shown is not the file that was uploaded. Its longest side is the same size the shop uses for a large product picture, and it is saved again at 960 and 480 pixels wide for the lists and for phones ([images.md](images.md)). A smaller picture is not enlarged. Anything that is not a picture is refused, including a file that is only named like one, and a picture with more pixels than the shop allows is refused before it is decoded. A cover larger than 5 MB is refused as well.

Replacing a cover saves the new picture first. The previous cover, every size of it, is removed only after that succeeds, so a cover that is refused leaves the one already on the post.

A post without a cover shows a placeholder in the list, and nothing on the post.

## Covers already on the site

A cover uploaded before the sizes were built has one file, and is shown at that one size. `php artisan metaforge:blog:covers:upgrade` draws them again ([images.md](images.md#covers-already-on-the-site)); `--dry-run` says what it would do.

## Not built

- **A picture inside the post.** Only the cover is made in several sizes.
- **Deleting the post does not delete the files.** Replacing the cover does.
- **A revision keeps the cover it was made with.** Replacing a cover removes the old files, so restoring a revision from before the replacement gives a post whose cover file is gone.

## Testing

`tests/Feature/Blog/BlogCoverTest.php`: a cover is stored as WebP, fitted to the longest side, and shown on the post; a note in the file does not survive; a text file and a picture with too many pixels are refused and leave nothing behind; a refused replacement keeps the existing file; a successful replacement removes the previous file.

`tests/Feature/Blog/BlogCoverSizesTest.php`: the three widths, what the pages send, and the upgrade command ([images.md](images.md#testing)).
