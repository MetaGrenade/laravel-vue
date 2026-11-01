# Pictures: sizes, the disk and a CDN

Every picture the site shows from its own storage is a WebP the site made, never the file that was uploaded (metadata and anything hidden in the file do not survive). This page covers the sizes that are kept, how a page picks one, and how to serve the files from a CDN.

> **Status:** built: blog covers are kept at three widths and each page asks for the one it needs; `PUBLIC_DISK_URL` points the public files at a CDN; a command draws older covers again. **Not built:** see [below](#not-built).

## What is kept

| Pictures | Files | Cut to |
|----------|-------|--------|
| Blog covers | `blog-covers/{id}-large.webp`, `-medium.webp`, `-small.webp` | 1600 pixels on the longest side, then 960 and 480 pixels wide |
| Product pictures | `products/{product}/{id}-large.webp`, `-medium.webp`, `-thumb.webp` | 1600, 800 and 320 pixels on the longest side (see [commerce.md](commerce.md#product-pictures)) |

No size is ever larger than the picture that was uploaded: a 300 pixel cover is kept three times at 300 pixels.

A cover is measured by its **width** because that is what a page lays out: a tall cover sits in a wide card, so cutting it to a longest side of 320 would leave it a blur. The post keeps only the path of the largest file. The other two are found by name, so nothing but the name says that a cover has them, and a cover stored the old way (one file, any name) is shown as that one file.

## How a page picks one

The list sends each cover with a `srcset` (its three files and their widths), and the page adds `sizes`, which says how wide the picture is drawn. The browser then fetches the smallest file that is sharp enough for its screen.

| Where | `sizes` | A 1280 pixel wide screen at 1x fetches |
|-------|---------|----------------------------------------|
| A card in the list | a third of the page on a wide screen, half on a medium one, all of it on a phone | the 480 wide file |
| The featured post at the top of the list | all of the page | the 1600 file |
| The post, and its preview | the page, at most 1200 pixels | the 1600 file |
| Recommended posts under a post | as a card in the list | the 480 wide file |

A phone at 1x takes the 480 file everywhere, and a screen at 2x asks for twice as many pixels, so it takes the next size up. A card on a wide screen is about 420 pixels across, and the 480 file has about a tenth of the pixels of a 1600 by 800 cover. The `sizes` are a good guess and no more: the server writes them before it knows how wide the browser window is, and a side menu makes a card narrower than a third of the window.

The address used for sharing (the preview picture other sites show, and the structured data) is always the largest file.

A post that has no cover shows a plain placeholder in a list and in the editor's preview, and nothing on the post. (It used to ask for `/images/default-cover.jpg`, a file this repository never had, so every such post showed a broken picture and made a request that could only fail.)

## Covers already on the site

A cover uploaded before this keeps working at its one size. To draw them all again at every size:

```bash
php artisan metaforge:blog:covers:upgrade --dry-run
php artisan metaforge:blog:covers:upgrade
```

Each cover is drawn again, the posts and the revisions that named the old file name the new one, and the old file is removed once nothing points at it. A cover whose file is gone, or that is not a picture the site accepts, is reported and left alone. It changes no post's *updated* time, and running it again does nothing. Run it when nobody is editing the covers; one that is replaced while it runs is noticed, and what the command had just drawn for it is removed again.

## Using a CDN

All public picture addresses come from one disk setting, so a CDN needs no change to a page:

1. Make a pull CDN whose origin is this site, so that `https://cdn.example.com/storage/blog-covers/x-large.webp` is fetched from `https://your-site.example/storage/blog-covers/x-large.webp`.
2. Set `PUBLIC_DISK_URL=https://cdn.example.com/storage` in `.env` and run `php artisan config:cache` again. Every cover and product picture address now starts with it. (Unset, it is `APP_URL` followed by `/storage`.)
3. The security headers already allow pictures from any `https:` address (`img-src` in `config/security.php`). If you have narrowed that, add the CDN to it.
4. Let the CDN keep the files for a long time. A picture is never rewritten: every upload gets a new name, and a replaced or deleted picture is removed, so the cover and product folders can be cached for a year. With nginx serving `/storage` from the origin:

   ```nginx
   location ~ ^/storage/(blog-covers|products)/ {
       add_header Cache-Control "public, max-age=31536000, immutable";
   }
   ```

   (An `add_header` inside a `location` replaces the ones set outside it, so repeat any you rely on there.) Do not give the whole of `/storage` that lifetime unless you know nothing else on the public disk is ever replaced under the same name.

`PUBLIC_DISK_URL` is the address of the disk named `public`, which holds the blog covers and, by default, the product pictures. Product pictures can be on another disk (`COMMERCE_IMAGE_DISK`), which has its own address. Support files, forum files and downloads are on a private disk and are not served this way at all ([private-uploads.md](private-uploads.md)).

## Not built

- **AVIF.** The GD extension does not always have it, and WebP is understood by every current browser. Another format is a second encoder to maintain for a smaller gain.
- **Width and height on the picture.** They are not stored, so the page holds the space with the box the picture sits in instead.
- **Object storage for covers.** Covers are written to the disk named `public`. Pointing that disk at a bucket is a configuration change that has not been tried here.
- **A picture inside a post.** Only the cover is made in several sizes.
- **Deleting a post does not delete its cover.** Replacing a cover does, and a revision still names the one it was replaced by: restoring that revision gives a post whose cover file is gone.
- **Responsive sizes for the post list in the admin area, or for avatars.**

## Testing

`tests/Feature/Blog/BlogCoverSizesTest.php`: a cover is kept at three widths; a tall cover is cut by its width; no size is larger than the upload; the list, the post and the recommended posts carry every size, and the sharing address stays the largest; a cover of one file and a post with no cover send no `srcset`; every address comes from the disk's own address (a CDN); replacing a cover removes every old size and a refused replacement keeps them all; the upgrade command draws a cover again, updates the post and its revisions, leaves the post's time alone, does nothing the second time, does nothing on a dry run, and leaves a missing file and a file that is not a picture as they are.
