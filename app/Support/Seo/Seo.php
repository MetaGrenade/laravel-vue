<?php

namespace App\Support\Seo;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Request-scoped SEO metadata.
 *
 * Controllers describe the current page (`app(Seo::class)->title(...)`) and the
 * result is shared with the frontend as the `seo` Inertia prop. The root Blade
 * view renders the same tags so crawlers that do not execute JavaScript still
 * see them, and the SeoHead Vue component keeps them in sync on client-side
 * navigation.
 */
class Seo
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $canonical = null;

    private ?string $image = null;

    private ?string $imageAlt = null;

    private string $type = 'website';

    private ?bool $indexable = null;

    /** @var array<string, string> */
    private array $article = [];

    /** @var list<array<string, mixed>> */
    private array $schemas = [];

    public function __construct(private readonly Request $request) {}

    public function title(?string $title): static
    {
        $this->title = $title !== null ? $this->clean($title, 120) : null;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description !== null && trim($description) !== ''
            ? $this->clean($description, 160)
            : null;

        return $this;
    }

    public function canonical(?string $url): static
    {
        $this->canonical = $url;

        return $this;
    }

    public function image(?string $url, ?string $alt = null): static
    {
        $this->image = $url !== null ? $this->absoluteUrl($url) : null;
        $this->imageAlt = $alt;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function noindex(bool $noindex = true): static
    {
        $this->indexable = ! $noindex;

        return $this;
    }

    /**
     * Open Graph article metadata.
     */
    public function article(?DateTimeInterface $publishedAt = null, ?DateTimeInterface $modifiedAt = null, ?string $author = null, ?string $section = null): static
    {
        $this->type = 'article';
        $this->article = array_filter([
            'published_time' => $publishedAt?->format(DATE_ATOM),
            'modified_time' => $modifiedAt?->format(DATE_ATOM),
            'author' => $author,
            'section' => $section,
        ]);

        return $this;
    }

    /**
     * Add a schema.org JSON-LD object (without the @context key).
     *
     * @param  array<string, mixed>  $schema
     */
    public function schema(array $schema): static
    {
        $this->schemas[] = ['@context' => 'https://schema.org', ...$schema];

        return $this;
    }

    /**
     * The full document title, as the frontend's title callback formats it.
     */
    public function documentTitle(): string
    {
        $siteName = (string) config('seo.site_name');

        return $this->title ? "{$this->title} - {$siteName}" : $siteName;
    }

    public function isIndexable(): bool
    {
        if (! config('seo.indexing')) {
            return false;
        }

        if ($this->indexable !== null) {
            return $this->indexable;
        }

        return ! $this->request->routeIs(...config('seo.noindex_routes', []));
    }

    public function canonicalUrl(): string
    {
        if ($this->canonical !== null) {
            return $this->canonical;
        }

        // Keep pagination in the canonical URL; drop sorting, filters and tracking parameters.
        $page = (int) $this->request->query('page', 1);

        return $page > 1
            ? $this->request->url().'?'.http_build_query(['page' => $page])
            : $this->request->url();
    }

    /**
     * The data shared with the frontend and rendered in the document head.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $siteName = (string) config('seo.site_name');
        $description = $this->description ?? $this->clean((string) config('seo.description'), 160);
        $image = $this->image ?? $this->defaultImage();
        $canonical = $this->canonicalUrl();
        $title = $this->title;

        $meta = [
            'description' => ['name' => 'description', 'content' => $description],
            'robots' => ['name' => 'robots', 'content' => $this->isIndexable() ? 'index, follow, max-image-preview:large' : 'noindex, nofollow'],
            'og:site_name' => ['property' => 'og:site_name', 'content' => $siteName],
            'og:type' => ['property' => 'og:type', 'content' => $this->type],
            'og:locale' => ['property' => 'og:locale', 'content' => (string) config('seo.locale')],
            'og:title' => ['property' => 'og:title', 'content' => $title ?? $siteName],
            'og:description' => ['property' => 'og:description', 'content' => $description],
            'og:url' => ['property' => 'og:url', 'content' => $canonical],
            'twitter:card' => ['name' => 'twitter:card', 'content' => $image ? 'summary_large_image' : 'summary'],
            'twitter:title' => ['name' => 'twitter:title', 'content' => $title ?? $siteName],
            'twitter:description' => ['name' => 'twitter:description', 'content' => $description],
        ];

        if ($image) {
            $meta['og:image'] = ['property' => 'og:image', 'content' => $image];
            $meta['twitter:image'] = ['name' => 'twitter:image', 'content' => $image];

            if ($this->imageAlt) {
                $meta['og:image:alt'] = ['property' => 'og:image:alt', 'content' => $this->imageAlt];
            }
        }

        if ($handle = config('seo.twitter_handle')) {
            $meta['twitter:site'] = ['name' => 'twitter:site', 'content' => (string) $handle];
        }

        foreach ($this->article as $key => $value) {
            $meta["article:{$key}"] = ['property' => "article:{$key}", 'content' => $value];
        }

        return [
            'title' => $title,
            'canonical' => $canonical,
            'meta' => $meta,
        ];
    }

    /**
     * Head elements for Inertia's `serverHead` option. Each element carries a
     * `data-inertia` key so a page's own <Head> tags can override it.
     *
     * @return list<string>
     */
    public function headElements(): array
    {
        $data = $this->toArray();

        // The raw page title; the frontend's title callback appends the site name.
        $elements = [
            sprintf('<title data-inertia="">%s</title>', e($data['title'] ?? '')),
            sprintf('<link rel="canonical" href="%s" data-inertia="canonical">', e($data['canonical'])),
        ];

        foreach ($data['meta'] as $key => $attributes) {
            $elements[] = sprintf('<meta %s data-inertia="%s">', $this->attributes($attributes), e($key));
        }

        foreach ($this->schemas as $index => $schema) {
            $elements[] = sprintf(
                '<script type="application/ld+json" data-inertia="schema-%d">%s</script>',
                $index,
                json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            );
        }

        return $elements;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function attributes(array $attributes): string
    {
        return collect($attributes)
            ->map(fn (string $value, string $name) => sprintf('%s="%s"', $name, e($value)))
            ->implode(' ');
    }

    private function defaultImage(): ?string
    {
        $image = config('seo.image');

        return $image ? $this->absoluteUrl((string) $image) : null;
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://', '//']) ? $url : url($url);
    }

    private function clean(string $value, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5)) ?? '');

        return Str::limit($text, $limit, '…', preserveWords: true);
    }
}
