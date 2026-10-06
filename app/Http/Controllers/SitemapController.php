<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Models\Product;
use App\Support\WebsiteSections;
use DateTimeInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * An XML sitemap of every public, indexable page.
     */
    public function sitemap(): Response
    {
        $xml = Cache::remember(
            'seo:sitemap:'.md5(json_encode(WebsiteSections::all())),
            config('seo.sitemap.cache_ttl', 3600),
            fn () => $this->buildSitemap(),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * robots.txt that blocks private areas and advertises the sitemap. When
     * indexing is disabled (e.g. staging), crawlers are blocked entirely.
     */
    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (! config('seo.indexing')) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (['/acp', '/settings', '/dashboard', '/api/', '/broadcasting', '/billing', '/cart', '/orders', '/search/results', '/blogs/preview', '/support/tickets', '/notifications', '/email', '/confirm-password', '/reset-password', '/forgot-password'] as $path) {
                $lines[] = "Disallow: {$path}";
            }

            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap');
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function buildSitemap(): string
    {
        $urls = [
            $this->url(route('home'), priority: '1.0'),
            $this->url(route('pricing'), priority: '0.8'),
        ];

        if (WebsiteSections::isEnabled('blog')) {
            $urls[] = $this->url(route('blogs.index'), priority: '0.8');

            Blog::query()
                ->where('status', 'published')
                ->latest('published_at')
                ->limit($this->limit())
                ->get(['slug', 'updated_at'])
                ->each(function (Blog $blog) use (&$urls) {
                    $urls[] = $this->url(route('blogs.view', ['slug' => $blog->slug]), $blog->updated_at, '0.7');
                });
        }

        if (WebsiteSections::isEnabled('forum')) {
            $urls[] = $this->url(route('forum.index'), priority: '0.8');

            ForumBoard::query()->get(['id', 'slug', 'updated_at'])->each(function (ForumBoard $board) use (&$urls) {
                $urls[] = $this->url(route('forum.boards.show', $board), $board->updated_at, '0.6');
            });

            ForumThread::query()
                ->where('is_published', true)
                ->with('board:id,slug')
                ->latest('last_posted_at')
                ->limit($this->limit())
                ->get(['id', 'forum_board_id', 'slug', 'last_posted_at', 'updated_at'])
                ->each(function (ForumThread $thread) use (&$urls) {
                    if ($thread->board === null) {
                        return;
                    }

                    $urls[] = $this->url(
                        route('forum.threads.show', [$thread->board, $thread]),
                        $thread->last_posted_at ?? $thread->updated_at,
                        '0.5',
                    );
                });
        }

        if (WebsiteSections::isEnabled('support')) {
            $urls[] = $this->url(route('support'), priority: '0.6');
        }

        if (WebsiteSections::isEnabled('commerce')) {
            $urls[] = $this->url(route('shop.index'), priority: '0.7');

            Product::query()
                ->where('is_active', true)
                ->limit($this->limit())
                ->get(['slug', 'updated_at'])
                ->each(function (Product $product) use (&$urls) {
                    $urls[] = $this->url(route('shop.products.show', $product), $product->updated_at, '0.6');
                });
        }

        $urls = array_slice($urls, 0, (int) config('seo.sitemap.max_urls', 45000));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $urls)."\n"
            .'</urlset>'."\n";
    }

    private function url(string $location, ?DateTimeInterface $lastModified = null, string $priority = '0.5'): string
    {
        return '  <url>'
            .'<loc>'.e($location).'</loc>'
            .($lastModified ? '<lastmod>'.$lastModified->format(DATE_ATOM).'</lastmod>' : '')
            .'<priority>'.$priority.'</priority>'
            .'</url>';
    }

    private function limit(): int
    {
        return (int) config('seo.sitemap.max_urls', 45000);
    }
}
