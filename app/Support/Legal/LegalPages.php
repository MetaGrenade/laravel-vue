<?php

namespace App\Support\Legal;

use App\Models\SystemSetting;
use App\Support\Security\HtmlSanitizer;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;

/**
 * Terms, the privacy policy and the cookie policy (docs/legal-pages.md).
 *
 * Stored as one system setting. A page with no text is not published.
 */
class LegalPages
{
    public const SETTING = 'legal_pages';

    /**
     * Slug to the translation key of its title. The order is the order of the footer.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'terms' => 'legal.pages.terms',
        'privacy' => 'legal.pages.privacy',
        'cookies' => 'legal.pages.cookies',
    ];

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * The published HTML, or null when the page is not on the site.
     */
    public function body(string $slug): ?string
    {
        $body = $this->rawBody($slug);

        if ($body === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $body;
    }

    public function updatedAt(string $slug): ?CarbonInterface
    {
        $entry = $this->entry($slug);
        $raw = $entry['updated_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Slugs that have text, in footer order.
     *
     * @return list<string>
     */
    public function publishedSlugs(): array
    {
        $slugs = [];

        foreach (array_keys(self::PAGES) as $slug) {
            if ($this->body($slug) !== null) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * What the admin form shows. An unpublished page is an empty string.
     *
     * @return array{terms: string, privacy: string, cookies: string}
     */
    public function fields(): array
    {
        return [
            'terms' => $this->body('terms') ?? '',
            'privacy' => $this->body('privacy') ?? '',
            'cookies' => $this->body('cookies') ?? '',
        ];
    }

    /**
     * A plain-text description for the page's meta tag.
     */
    public function description(string $slug): string
    {
        $body = $this->body($slug);
        $fallback = (string) __('legal.meta');

        if ($body === null) {
            return $fallback;
        }

        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim(is_string($text) ? $text : '');

        if ($text === '') {
            return $fallback;
        }

        return Str::limit($text, 160);
    }

    /**
     * Changes when a published page changes, so a cached sitemap is rebuilt.
     */
    public function stamp(): string
    {
        $parts = [];

        foreach (array_keys(self::PAGES) as $slug) {
            $parts[] = $slug.':'.md5($this->body($slug) ?? '');
        }

        return implode('|', $parts);
    }

    /**
     * Save all three pages. HTML is reduced to what is safe to show. A page whose text did not change keeps its date.
     *
     * @param  array<string, string|null>  $bodies
     */
    public function save(array $bodies): void
    {
        $stored = $this->stored();

        foreach (array_keys(self::PAGES) as $slug) {
            $raw = $bodies[$slug] ?? null;
            $clean = $this->clean(is_string($raw) ? $raw : null);
            $current = $this->rawBody($slug);

            if ($clean === null) {
                unset($stored[$slug]);

                continue;
            }

            if ($current === $clean && isset($stored[$slug]) && is_array($stored[$slug])) {
                continue;
            }

            $stored[$slug] = [
                'body' => $clean,
                'updated_at' => now()->toAtomString(),
            ];
        }

        SystemSetting::set(self::SETTING, $stored);
    }

    private function clean(?string $html): ?string
    {
        $clean = trim($this->sanitizer->article($html));

        if ($clean === '') {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $clean;
    }

    private function rawBody(string $slug): ?string
    {
        $body = $this->entry($slug)['body'] ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $slug): array
    {
        $entry = $this->stored()[$slug] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        $normalised = [];

        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $value = SystemSetting::get(self::SETTING, []);

        if (! is_array($value)) {
            return [];
        }

        $stored = [];

        foreach ($value as $slug => $entry) {
            if (is_string($slug)) {
                $stored[$slug] = $entry;
            }
        }

        return $stored;
    }
}
