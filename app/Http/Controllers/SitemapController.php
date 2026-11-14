<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use App\Support\Legal\LegalPages;
use App\Support\WebsiteSections;
use DateTimeInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * An XML sitemap of every public, indexable page.
     */
    public function sitemap(): Response
    {
        $xml = Cache::remember(
            $this->cacheKey(),
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

    /**
     * Every entry, in the order of the file, up to `max_urls` in all: the fixed pages, then the blog, the forum, the shop and, last, the
     * members, so that a very large directory is what is cut when the list is full.
     *
     * It was built by reading the most `max_urls` rows of each kind as models, making an entry for all of them and cutting the list to
     * `max_urls` at the end, so a site of 50,000 threads, 20,000 posts and 20,000 members read 90,000 models at once (+82 MB, 3.6 s) and
     * one past that read 155,000 (+128 MB, 2.5 to 6 s), to publish 45,000. Now there is one budget for the whole file: each kind is
     * asked for only what room is left, a row at a time, as plain rows (a model costs more than the whole entry, and cursor() makes one for each row), only the columns the entry needs, and a kind
     * that comes after the file is full is not read at all. The text is the same.
     */
    private function buildSitemap(): string
    {
        $limit = $this->limit();
        // The text is built in one string, header first, so that the finished file is not copied to put a header on it.
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $count = 0;

        $add = function (string $location, ?DateTimeInterface $lastModified = null, string $priority = '0.5') use (&$body, &$count, $limit): void {
            if ($count >= $limit) {
                return;
            }

            $body .= $this->url($location, $lastModified, $priority)."\n";
            $count++;
        };
        $room = function () use (&$count, $limit): int {
            return max($limit - $count, 0);
        };

        $add(route('home'), priority: '1.0');
        $add(route('members.index'), priority: '0.6');
        $add(route('pricing'), priority: '0.8');

        $legal = app(LegalPages::class);

        foreach ($legal->publishedSlugs() as $slug) {
            $add(route('legal.show', ['page' => $slug]), $legal->updatedAt($slug), '0.3');
        }

        if (WebsiteSections::isEnabled('blog')) {
            $add(route('blogs.index'), priority: '0.8');

            if ($room() > 0) {
                $blogs = Blog::query()
                    ->where('status', 'published')
                    ->latest('published_at')
                    ->limit($room())
                    ->select(['slug', 'updated_at'])
                    ->toBase()
                    ->cursor();

                foreach ($blogs as $blog) {
                    $add(route('blogs.view', ['slug' => $blog->slug]), $this->moment($blog->updated_at), '0.7');
                }
            }
        }

        if (WebsiteSections::isEnabled('forum')) {
            $add(route('forum.index'), priority: '0.8');
            $add(route('leaderboard'), priority: '0.5');

            // Boards are few, and the threads are read without them: a cursor cannot load a relation.
            $boards = ForumBoard::query()->get(['id', 'slug', 'updated_at'])->keyBy('id');

            foreach ($boards as $board) {
                $add(route('forum.boards.show', $board), $board->updated_at, '0.6');
            }

            if ($room() > 0) {
                $threads = ForumThread::query()
                    ->where('is_published', true)
                    ->latest('last_posted_at')
                    ->limit($room())
                    ->select(['forum_board_id', 'slug', 'last_posted_at', 'updated_at'])
                    ->toBase()
                    ->cursor();

                foreach ($threads as $thread) {
                    $board = $boards->get($thread->forum_board_id);

                    if ($board === null) {
                        continue;
                    }

                    $add(
                        route('forum.threads.show', ['board' => $board->getRouteKey(), 'thread' => $thread->slug]),
                        $this->moment($thread->last_posted_at ?? $thread->updated_at),
                        '0.5',
                    );
                }
            }
        }

        if (WebsiteSections::isEnabled('support')) {
            $add(route('support'), priority: '0.6');
        }

        if (WebsiteSections::isEnabled('commerce')) {
            $add(route('shop.index'), priority: '0.7');

            if ($room() > 0) {
                $products = Product::query()
                    ->where('is_active', true)
                    ->limit($room())
                    ->select(['slug', 'updated_at'])
                    ->toBase()
                    ->cursor();

                foreach ($products as $product) {
                    $add(route('shop.products.show', ['product' => $product->slug]), $this->moment($product->updated_at), '0.6');
                }
            }
        }

        // After the rest, so a large directory is what gets cut when the sitemap is full.
        if ($room() > 0) {
            $members = User::query()
                ->where('is_banned', false)
                ->latest('updated_at')
                ->limit($room())
                ->select(['id', 'updated_at'])
                ->toBase()
                ->cursor();

            foreach ($members as $member) {
                $add(route('members.show', ['user' => $member->id]), $this->moment($member->updated_at), '0.4');
            }
        }

        return $body.'</urlset>'."\n";
    }

    private function url(string $location, ?DateTimeInterface $lastModified = null, string $priority = '0.5'): string
    {
        return '  <url>'
            .'<loc>'.e($location).'</loc>'
            .($lastModified ? '<lastmod>'.$lastModified->format(DATE_ATOM).'</lastmod>' : '')
            .'<priority>'.$priority.'</priority>'
            .'</url>';
    }

    /**
     * A time as the database gives it (a string in the site's timezone, or nothing), as the `lastmod` of an entry. A model's own date
     * reads the same string.
     */
    private function moment(mixed $value): ?DateTimeInterface
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function limit(): int
    {
        return (int) config('seo.sitemap.max_urls', 45000);
    }

    /**
     * Sections and the legal pages share this cache. A published page has to change the key, or the old sitemap is served.
     */
    private function cacheKey(): string
    {
        $payload = json_encode([
            'sections' => WebsiteSections::all(),
            'legal' => app(LegalPages::class)->stamp(),
        ]);

        return 'seo:sitemap:'.md5($payload === false ? '' : $payload);
    }
}
