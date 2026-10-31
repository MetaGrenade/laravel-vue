# Bundle budget

How CI keeps the front end from getting heavier unnoticed: after `npm run build`, the size of what the browser downloads is held to numbers that are set on purpose.

> **Status:** in place and run by CI (the `bundle (size budget)` job). Not built: a budget on how fast a page renders (that is the Lighthouse work in the roadmap), a budget on the images a page shows (the image pipeline), and a budget for the server-rendered build.

## Why

A page can pass every test and still be slow to open, because it ships more JavaScript than it needs. Nothing in a pull request shows that: the diff is a few lines, and the library that makes the page heavy is one `import` away. The roadmap's target is a Largest Contentful Paint under 2.5 s on 4G for the home page, blog post and product pages, and bytes are the part of that a pull request can change.

## What is measured

Sizes are **gzipped bytes**, which is what every host can send (brotli is smaller still, where it is on). They are read from the build's `manifest.json`, so what counts is what the browser requests, not what is in the source.

| Number | What it is | Now | Allowed |
|---|---|---|---|
| JavaScript every page loads | The app entry and everything it imports statically | 101,205 | 112,000 |
| Stylesheet every page loads | `resources/css/app.css` | 26,463 | 29,500 |
| Largest script | Any one script the build makes, loaded at once or on demand | 140,533 | 155,000 |
| The typical page | What the middle page of the 136 adds to the first two (its own chunk, its stylesheet, the chunks it shares with other pages such as the layout), counted once | 81,365 | 89,500 |
| Any one page | The same for each page | 110,000 or its own limit | |

Pages are loaded on demand, so a heavy library that one page uses costs that page and no other. A first visit to the home page is the 127,668 every page loads plus the 99,309 `Welcome.vue` adds: about 227 kB needed before it appears (the background prefetch, below, comes on top).

The typical page is there because a change to the layout or a shared component makes every page heavier by a little, and no page is then over its own limit. The middle page moves when that happens and does not when one page grows.

The largest script is there because a library imported lazily (the Tebex checkout script, for one) is not part of any page's number and could otherwise grow without anything noticing. It also holds the editor's chunk, which the forum pages load, to what it weighs now.

## Pages that are allowed more

A page that needs a heavy library has a limit of its own in `scripts/bundle-budget.json`, with the reason next to it:

| Page | Limit | Why |
|---|---|---|
| `ForumThreadCreate.vue` | 233,000 | The rich-text editor (141 kB): the page is for writing |
| `acp/Support.vue`, `acp/Blogs.vue`, `Dashboard.vue`, `acp/Dashboard.vue` | 161,000 to 192,000 | Charts (68 kB) |
| `acp/CommerceProductEdit.vue` | 122,000 | Options, variants, prices and pictures on one page |

A limit for a page that no longer exists fails the check, so the file does not collect old entries, and a limit without a reason is refused.

### The editor on the thread page

`ForumThreadView.vue` used to be the first row, at 252,000: it imported the rich-text editor outright, so the page itself needed 141 kB for a reply box a guest never sees. It now draws the editor with `LazyRichTextEditor` (in `resources/js/components/editor/`), which downloads the code the first time the reply box or the edit dialog is drawn. Until it arrives, a stand-in with the editor's id and height holds the place (so nothing jumps and the "quote" button has somewhere to scroll), and if the code cannot be fetched (a tab opened before a deploy, no connection) the box says so and asks for a reload. The page adds 89,824 instead of 229,000 and no longer has a limit of its own. A reader who cannot reply, a locked thread and a guest never draw the editor. What the page puts in the box before the editor exists is kept (the "quote" button works while the editor is still downloading): the editor takes up the page's text when it is drawn and, if the member has a saved draft, puts the draft first and that text after it, as the button does on an editor that is already drawn.

`ForumThreadCreate.vue` still imports the editor itself: the page exists to write in it, so waiting for the code would only delay the page.

Use `LazyRichTextEditor` instead of `RichTextEditor` wherever the editor is behind a condition, a dialog or a tab.

## When a check fails

The failure names what is over and by how much, and for a page what made it heavy. This one is made up:

```
1 over budget:
  - Pricing.vue adds 118,204 B gzipped to the shared bundle, over the 110,000 B it is allowed (largest: chartlib-3f9a.js 61,422, ImpersonationBanner-x.js 39,208, ...).
```

- If the page pulled in a library it does not need, or one it needs only after a click, import it where it is used (`defineAsyncComponent`, `import()`).
- If the growth is real and worth it, raise the number in `scripts/bundle-budget.json` **in the same change**, and say why in the pull request. A page that needs a heavy library on purpose gets a limit of its own with a reason, instead of raising the default for every page.
- If it is the typical page or what every page loads, something shared has grown: look at the layout and `app.ts` first.

## The rule for the numbers

A number is what the build weighs today plus a tenth (rounded up to the next kB), not a target. Query budgets allow a quarter; this one allows less because every visitor pays for these bytes, and because a tenth of the JavaScript every page loads is already about 10 kB. When something gets lighter, lower the number in the change that did it.

## Running it

```bash
npm run build          # the numbers come from the build, so build first
npm run bundle:check   # prints the numbers and the pages closest to their limit; exits 1 when something is over
npm run bundle:test    # tests of the check itself (node:test, no dependencies)
```

`bundle:test` builds small fake builds and checks that the check finds what it should: a dynamic import is not counted as loaded, a shared chunk is counted once, the layout moving the typical page fails, a limit at exactly the size passes and one byte under fails, an old limit fails.

## What it does not do

- It does not run the app. How long a page takes to draw depends on more than its bytes (images, fonts, the server), and that is the Lighthouse budget still to come.
- It does not measure the server-rendered build (`build:ssr`), which runs on the server and is not downloaded.
- It does not count a chunk a page loads after the user does something, apart from holding it to the largest-script limit.
- It does not count what the browser fetches after the page has loaded. `Vite::prefetch(concurrency: 3)` in `AppServiceProvider` makes every page, for every visitor and guests among them, fetch the rest of the build in the background once it has drawn: all 257 files, about 990 kB gzipped, the admin pages included, at low priority and three at a time. That keeps moving between pages quick, and it is why a guest on a thread still downloads the editor a moment after the page appears. The budget is about what a page needs to appear and work, which is what its first view waits for, not the total a visit costs. Whether the prefetch should reach that far (a guest has no use for the admin pages) is a separate decision.
