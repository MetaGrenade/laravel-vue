# Blog covers

A post can have a cover picture. It is shown on the post, and it is a public image.

> **Status:** built: a new cover, or a cover that replaces one, is decoded and saved again as WebP. **Not built:** see [below](#not-built).

## Using it

On a new post, or when editing one, choose a cover. A JPEG, PNG, GIF or WebP is decoded and saved again as one WebP on the public disk, so the file that is shown is not the file that was uploaded. Its longest side is the same size the shop uses for a large product picture. A smaller picture is not enlarged. Anything that is not a picture is refused, including a file that is only named like one, and a picture with more pixels than the shop allows is refused before it is decoded. A cover larger than 5 MB is refused as well.

Replacing a cover saves the new picture first. The previous file is removed only after that succeeds, so a cover that is refused leaves the one already on the post. Covers that were uploaded before this was built stay as they are until they are replaced.

## Not built

- **Covers already on the site.** They are not drawn again on their own.
- **Several sizes, or a picture inside the post.** One cover is stored. Responsive sizes are part of the later image pipeline.
- **Deleting the post does not delete the file.** Replacing the cover does.

## Testing

`tests/Feature/Blog/BlogCoverTest.php`: a cover is stored as WebP, fitted to the longest side, and shown on the post; a note in the file does not survive; a text file and a picture with too many pixels are refused and leave nothing behind; a refused replacement keeps the existing file; a successful replacement removes the previous file.
