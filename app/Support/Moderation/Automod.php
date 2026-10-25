<?php

namespace App\Support\Moderation;

use App\Models\AutomodRule;
use App\Models\ForumPost;
use App\Models\User;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Looks at what a member is about to post in the forum and says whether it breaks a rule an administrator has set (docs/automod.md).
 *
 * Staff are never checked. Everybody else is checked against every enabled rule that is for them (a rule may be only for members whose
 * account is newer than some days). A post that breaks a rule that refuses is refused; otherwise a post that breaks a rule that holds is
 * held for a moderator. The rule that decided is counted.
 */
class Automod
{
    /**
     * @param  string  $title  The title of a thread (empty for a reply).
     * @param  string  $html  What was written, as it will be kept.
     */
    public function check(User $author, string $title, string $html): ?AutomodVerdict
    {
        if ($author->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES)) {
            return null;
        }

        $held = null;

        foreach ($this->rules() as $rule) {
            if (! $this->isFor($rule, $author)) {
                continue;
            }

            $detail = $this->detail($rule, $title, $html, $author);

            if ($detail === null) {
                continue;
            }

            $verdict = new AutomodVerdict($rule, $detail);

            if ($verdict->rejects()) {
                $this->count($rule);

                return $verdict;
            }

            $held ??= $verdict;
        }

        if ($held !== null) {
            $this->count($held->rule);
        }

        return $held;
    }

    /**
     * What each rule would make of a text, for a moderator trying it out: the rules that look at the text alone (not those that look at
     * how a member has been posting) and nothing is counted.
     *
     * @return list<AutomodVerdict>
     */
    public function test(string $title, string $html): array
    {
        $verdicts = [];

        foreach ($this->rules() as $rule) {
            $detail = $this->detail($rule, $title, $html, null);

            if ($detail !== null) {
                $verdicts[] = new AutomodVerdict($rule, $detail);
            }
        }

        return $verdicts;
    }

    /**
     * @return Collection<int, AutomodRule>
     */
    private function rules(): Collection
    {
        return AutomodRule::query()->where('is_enabled', true)->orderBy('id')->get();
    }

    private function isFor(AutomodRule $rule, User $author): bool
    {
        return $rule->only_new_days === null
            || $rule->only_new_days <= 0
            || $author->created_at === null
            || $author->created_at->greaterThan(now()->subDays($rule->only_new_days));
    }

    private function count(AutomodRule $rule): void
    {
        $rule->increment('hits', 1, ['last_hit_at' => now()]);
    }

    /**
     * What the rule found in this, or null when it found nothing.
     */
    private function detail(AutomodRule $rule, string $title, string $html, ?User $author): ?string
    {
        $text = self::plain(trim($title.' '.$html));
        $settings = $rule->settings;

        return match ($rule->type) {
            AutomodRule::WORDS => $this->words($settings, $text),
            AutomodRule::LINKS => $this->links($settings, $html),
            AutomodRule::DUPLICATE => $author === null ? null : $this->duplicate($settings, $author, self::plain($html)),
            AutomodRule::RATE => $author === null ? null : $this->rate($settings, $author),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function words(array $settings, string $text): ?string
    {
        $wholeWords = (bool) ($settings['whole_words'] ?? false);

        foreach ((array) ($settings['phrases'] ?? []) as $phrase) {
            $phrase = trim((string) $phrase);

            if ($phrase === '') {
                continue;
            }

            $found = $wholeWords
                ? preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}_])/iu', $text) === 1
                : mb_stripos($text, $phrase) !== false;

            if ($found) {
                return 'contains "'.Str::limit($phrase, 60, '…').'"';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function links(array $settings, string $html): ?string
    {
        $urls = self::urls($html);
        $max = $settings['max_links'] ?? null;

        if (is_numeric($max) && count($urls) > (int) $max) {
            return count($urls).' links, and the most allowed is '.(int) $max;
        }

        $blocked = array_values(array_filter(array_map(
            fn ($domain) => self::domain((string) $domain),
            (array) ($settings['blocked_domains'] ?? []),
        )));

        foreach ($urls as $url) {
            $host = self::host($url);

            foreach ($blocked as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return 'links to '.$domain;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function duplicate(array $settings, User $author, string $text): ?string
    {
        $minutes = max(1, (int) ($settings['minutes'] ?? 60));
        $copies = max(1, (int) ($settings['copies'] ?? 1));
        $normal = Str::lower($text);

        // Their last few posts in the window, held ones too: a spammer's held copies are the evidence.
        $same = ForumPost::query()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->where('user_id', $author->id)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->limit(30)
            ->pluck('body')
            ->filter(fn ($body) => Str::lower(self::plain((string) $body)) === $normal)
            ->count();

        return $same >= $copies ? 'the same text was posted '.$same.' '.($same === 1 ? 'time' : 'times').' in the last '.$minutes.' minutes' : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function rate(array $settings, User $author): ?string
    {
        $posts = max(1, (int) ($settings['posts'] ?? 5));
        $minutes = max(1, (int) ($settings['minutes'] ?? 10));

        $recent = ForumPost::query()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->where('user_id', $author->id)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();

        return $recent >= $posts ? $recent.' posts in the last '.$minutes.' minutes' : null;
    }

    /**
     * The words of some HTML as a reader gets them: no tags (a paragraph break is a space), entities decoded, runs of spaces one.
     */
    public static function plain(string $html): string
    {
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Every link in some HTML, once each: the ones in `href`s and the ones typed out.
     *
     * @return list<string>
     */
    public static function urls(string $html): array
    {
        preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $hrefs);
        preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~i', self::plain($html), $typed);

        $urls = [];

        foreach ([...$hrefs[1], ...$typed[0]] as $url) {
            $url = rtrim(trim($url), '.,;:!?)]}');

            if (preg_match('~^(?:https?://|www\.)~i', $url) === 1) {
                $urls[Str::lower($url)] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * The host of a link, without a leading `www.`.
     */
    private static function host(string $url): string
    {
        $host = parse_url(preg_match('~^https?://~i', $url) === 1 ? $url : 'http://'.$url, PHP_URL_HOST);

        return self::domain(is_string($host) ? $host : '');
    }

    /**
     * A domain as it is compared: lower case, with no scheme, path or `www.`.
     */
    private static function domain(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $value);
        $value = explode('/', $value)[0];

        return (string) preg_replace('/^www\./', '', $value);
    }
}
