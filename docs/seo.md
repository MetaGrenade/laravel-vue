# Search engines and sharing

What a page tells a search engine or a chat app about itself (its title, description, picture and structured data), what the sitemap and `robots.txt` list, when the site asks to be indexed at all, and how to describe a page of your own.

> **Status:** built and covered by tests (`tests/Feature/SeoTest.php`). Not measured against a search engine: nobody can promise a ranking.

## What every page sends

A controller describes the page it is about to show, and the site draws the same tags twice: once on the server, in `resources/views/app.blade.php`, so a crawler that does not run JavaScript sees them, and once in the browser, so the tags follow the visitor from page to page without a reload.

- **Title and description.** The title has the site's name added. A description is cut at 160 characters and a title at 120.
- **The canonical address**, so the same page reached by two addresses counts once.
- **Open Graph and Twitter tags** for the picture and text a chat app or a social site shows when somebody pastes a link.
- **Robots tags**: `noindex, nofollow` on a page that should not be found (below).
- **Structured data** (JSON-LD) for the pages that have it: blog posts (with a breadcrumb), products (with their price and rating) and forum threads.

A page's own `<Head title="…">` still sets the title of the browser tab.

## Describing a page of your own

`App\Support\Seo\Seo` is one object per request. Every method returns the object, so a controller chains them:

```php
app(Seo::class)
    ->title($post->title)
    ->description($post->excerpt)
    ->image($coverUrl)
    ->article(publishedAt: $post->published_at, author: $post->author->name)
    ->schema(['@type' => 'BlogPosting', 'headline' => $post->title]);
```

The methods are `title`, `description`, `canonical`, `image` (with an alternative text), `type`, `noindex`, `article` (published and modified times, author, section) and `schema` (add a JSON-LD object; call it again for another). A page that sets none of them gets the site's defaults, below. [Extending MetaForger](extending.md) says where a new page's controller goes.

## The defaults

They come from `config/seo.php`, and every one has a `SEO_*` setting in [the configuration reference](configuration.md#search-engines-and-social-cards): the site name (`SEO_SITE_NAME`, else `APP_NAME`), the description, the home page's title and description, the share picture (1200 by 630 pixels), the Twitter / X handle and the locale.

The text that ships is about MetaForger itself. Change `SEO_DESCRIPTION`, `SEO_HOME_TITLE` and `SEO_HOME_DESCRIPTION` before you open a site to the public, or the home page will describe the boilerplate rather than yours ([Theming and rebranding](theming.md)).

## Whether the site is indexed

`SEO_INDEXING` decides. It is on in production and off everywhere else, so a staging site or a laptop is not found by accident. Off, `robots.txt` says `Disallow: /` and every page is marked `noindex`. On, the pages that belong to a person or to the staff are still marked `noindex`: the routes listed under `noindex_routes` in `config/seo.php` (the admin area, settings, sign-in and registration, the dashboard, notifications, search results, the cart and the orders, support tickets, blog previews and the sign-in redirects).

## The sitemap and `robots.txt`

`/sitemap.xml` lists the public pages of the sections that are switched on in **System settings → Website Sections**: the home page, pricing, the members directory, the legal pages that have text, the blog and its published posts, the forum with its boards, published threads and the leaderboard, the support page, and the shop with its active products. Members who are not banned come last, so that a very large directory is what is cut when the list reaches `max_urls` (45 000, the sitemap protocol allows 50 000). The file is kept for `SEO_SITEMAP_CACHE_TTL` seconds (an hour) and is built again at once when a section is switched on or off or a legal page is published. Its own response carries `X-Robots-Tag: noindex`, so the file itself does not turn up in search results.

`/robots.txt` blocks the private areas (the admin area, settings, the dashboard, the API, the cart, orders, tickets, notifications and the password and email routes) and names the sitemap. A web manifest at `/site.webmanifest` carries the site's name (the same `SEO_SITE_NAME`), the icons and the two colours, which are written in the controller.

All of them are plain documents that start no session and carry a `Cache-Control` and an `ETag`, so a cache or a CDN may keep them ([Documents and caching](http-caching.md)).

## Rendering on the server

By default a page is drawn in the browser. A crawler that runs JavaScript copes, and the head tags above are in the HTML from the first byte either way. Rendering the whole page on the server as well gives crawlers (and slow phones) the words of the page without waiting for a script:

```bash
npm run build:ssr
php artisan inertia:start-ssr     # keep it running under a process manager
```

and set `INERTIA_SSR_ENABLED=true`. If the SSR process is not there, pages are drawn in the browser instead, so a crash costs speed and not the site. `composer dev:ssr` runs it all together while you develop. [Deploying](deployment.md#server-side-rendering-optional) has the process manager; the Docker image does not include a rendering server ([Running in Docker](docker.md)).

## Testing

`tests/Feature/SeoTest.php` holds the tags a page sends, the noindex rules, `robots.txt` and what the sitemap lists (a draft is not in it). The Lighthouse run ([Lighthouse](lighthouse.md)) scores the SEO category on the pages it opens.
