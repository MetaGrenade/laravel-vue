# Pictures: sizes, the disk and a CDN

Every picture the site shows from its own storage is a WebP the site made, never the file that was uploaded (metadata and anything hidden in the file do not survive). This page covers the sizes that are kept, how a page picks one, and how to serve the files from a CDN.

> **Status:** built: blog covers are kept at three widths and each page asks for the one it needs; `PUBLIC_DISK_URL` points the public files at a CDN; a command draws older covers again. **Not built:** see [below](#not-built).

## What is kept

| Pictures | Files | Cut to |
|----------|-------|--------|
| Blog covers | `blog-covers/{id}-{width}x{height}-large.webp`, `{id}-medium.webp`, `{id}-small.webp` | 1600 pixels on the longest side, then 960 and 480 pixels wide |
| Product pictures | `products/{product}/{id}-large.webp`, `-medium.webp`, `-thumb.webp` | 1600, 800 and 320 pixels on the longest side (see [commerce.md](commerce.md#product-pictures)) |

No size is ever larger than the picture that was uploaded: a 300 pixel cover is kept three times at 300 pixels.

A cover is measured by its **width** because that is what a page lays out: a tall cover sits in a wide card, so cutting it to a longest side of 320 would leave it a blur. The post keeps only the path of the largest file, and its name says how big the cover is (`-1600x400-`). The other two are found by name, so nothing but the name says that a cover has them or how wide it is, and a cover stored the old way (one file, any name) is shown as that one file. There is no database column for it, so a revision that names a cover has its sizes too.

## How a page picks one

The list sends each cover with a `srcset` (its files and their real widths) and its proportions (`cover_aspect`, the width over the height), and the page adds `sizes`, which says how wide the picture has to be. The browser then fetches the smallest file that is sharp enough for its screen.

| Where | The box the cover is drawn in | A 1280 pixel wide screen at 1x fetches (a 2:1 cover) |
|-------|-------------------------------|-----------------------------------------------------|
| A card in the list | 16:7, a third of the page on a wide screen, half on a medium one, all of it on a phone | the 480 wide file |
| The featured post at the top of the list | 16rem tall (18rem from 640 pixels), across the page | the 1600 file |
| The post, and its preview | 16rem tall, across the page, at most 1200 pixels | the 1600 file |
| Recommended posts under a post | 16:9, as a card in the list | the 480 wide file |

A phone at 1x takes the 480 file for a card, and a screen at 2x asks for twice as many pixels, so it takes the next size up. A card on a wide screen is about 420 pixels across, and the 480 file has about a tenth of the pixels of a 1600 by 800 cover. The `sizes` are a good guess and no more: the server writes the page before it knows how wide the browser window is, and a side menu makes a card narrower than a third of the window.

### A wide cover in a short box

A cover is drawn so that it fills its box on both sides and the rest is cut off (`object-cover`). A cover that is wider than its box is scaled to the box's **height**, so it is wider than the box and needs more pixels across than the box is wide. A 1600 by 400 cover in the featured box (256 pixels tall) on a 390 pixel screen is drawn 1024 pixels wide and cut to 390; if the page said only "the box is 390 wide", the browser would choose the 480 pixel file and stretch it to 1024.

So the page says how wide the picture really is: for a box of fixed height, the larger of the box's width and its height times the cover's proportions (`max(100vw, 1024px)`); for a box with a ratio of its own, the box's share of the screen, times how many times wider than the box the cover is (a 4:1 cover in a 16:7 card is 1.75 times as wide, so `57.75vw` where the card is a third of a wide screen). `resources/js/lib/coverSizes.ts` does the arithmetic and `scripts/cover-sizes.test.mjs` pins it. A cover that is no wider than its box needs only the box's width, and the numbers above are what they always were. If a browser did not understand `max()` in `sizes` it would ignore the attribute and use `100vw`, which is what the page said before.

Measured with a 1600 by 400 cover on a 390 pixel screen (2x), in a browser that had not loaded any of the files: the old `sizes` chose the 960 pixel file for the featured box and the post (stretched about 2.1 times to fill the box), and for a card in the list (about 1.3 times). The new ones choose the 1600 pixel file, which is as sharp as a cover of that size can be.

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
- **Width and height attributes on the picture.** The size is in the file name now, but the page holds the space with the box the picture sits in, so the attributes would add nothing.
- **Object storage for covers.** Covers are written to the disk named `public`. Pointing that disk at a bucket is a configuration change that has not been tried here.
- **A picture inside a post.** Only the cover is made in several sizes.
- **Deleting a post does not delete its cover.** Replacing a cover does, and a revision still names the one it was replaced by: restoring that revision gives a post whose cover file is gone.
- **Responsive sizes for the post list in the admin area, or for avatars.**

## Testing

`tests/Feature/Blog/BlogCoverSizesTest.php`: a cover is kept at three widths and the largest file's name says its size; a tall cover is cut by its width; no size is larger than the upload; the list, the post and the recommended posts carry every size and the cover's proportions, and the sharing address stays the largest; the widths in the `srcset` are the widths of the files (a narrow cover lists a size once); a cover of one file and a post with no cover send no `srcset`; every address comes from the disk's own address (a CDN); replacing a cover removes every old size and a refused replacement keeps them all; the upgrade command draws a cover again, updates the post and its revisions, leaves the post's time alone, does nothing the second time, does nothing on a dry run, and leaves a missing file and a file that is not a picture as they are.

`scripts/cover-sizes.test.mjs` (`npm run bundle:test`, which CI runs): the `sizes` for a box of fixed height and for a box with a ratio of its own, for a wide cover, a cover no wider than its box, one whose proportions are not known, and a box that stops growing at a width of its own.
