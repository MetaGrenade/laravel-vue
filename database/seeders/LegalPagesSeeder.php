<?php

namespace Database\Seeders;

use App\Support\Legal\CookieConsent;
use App\Support\Legal\LegalPages;
use Illuminate\Database\Seeder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Starting text for the terms, the privacy policy and the cookie policy (docs/legal-pages.md).
 *
 * The text is in `database/seeders/legal/` and is written for what this software does: its cookies, the data it keeps, the services it
 * talks to. What only the operator can know is left as a [Bracketed item] (see PLACEHOLDERS), and what the running site can say about
 * itself (the site name, how long a search is kept) is filled in from its configuration.
 *
 * A page that already has text is not touched, so this is safe to run again and on a live site:
 * `php artisan db:seed --class=LegalPagesSeeder`.
 */
class LegalPagesSeeder extends Seeder
{
    /**
     * Every [Bracketed item] in the text, and what to put there. A test keeps this list and the text the same.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '[Operator name]' => 'The person or company that runs the site.',
        '[Operator address]' => 'Their postal address.',
        '[Contact email]' => 'Where people write about the terms, their data or a refund.',
        '[Minimum age]' => 'The youngest age that may have an account, as a number of years.',
        '[Refund period]' => 'How long after buying someone may ask for a refund, for example 14 days.',
        '[Governing law]' => 'The country or state whose law applies to the terms.',
        '[Courts]' => 'The place whose courts hear a dispute.',
        '[Hosting provider]' => 'Who hosts the site.',
        '[Email provider]' => 'The service that sends the site\'s email (the MAIL_MAILER you use).',
        '[Record keeping period]' => 'How long orders and invoices are kept, as tax law in your country requires.',
    ];

    public function run(LegalPages $legal): void
    {
        $current = $legal->fields();
        $bodies = [];
        $added = [];
        $kept = [];

        foreach (array_keys(LegalPages::PAGES) as $slug) {
            if (trim($current[$slug]) !== '') {
                $bodies[$slug] = $current[$slug];
                $kept[] = $slug;

                continue;
            }

            $bodies[$slug] = self::render($slug);
            $added[] = $slug;
        }

        if ($added === []) {
            $this->report([['info', 'All three legal pages already have text. Nothing was changed.']]);

            return;
        }

        $legal->save($bodies);

        $lines = [['info', 'Legal pages: starting text added for '.$this->titles($added).'.']];

        if ($kept !== []) {
            $lines[] = ['line', '  Left as they were: '.$this->titles($kept).'.'];
        }

        $left = [];

        foreach ($added as $slug) {
            $left = [...$left, ...self::placeholdersIn($bodies[$slug])];
        }

        $lines[] = ['line', ''];
        $lines[] = ['warn', 'Before the pages go live, open Admin > Legal pages and fill in:'];

        foreach (self::PLACEHOLDERS as $placeholder => $meaning) {
            if (in_array($placeholder, $left, true)) {
                $lines[] = ['line', '  '.$placeholder.'  '.$meaning];
            }
        }

        $lines[] = ['line', '  Then delete the note at the top of each page. It says the text is a starting point, not legal advice.'];

        $this->report($lines);
    }

    /**
     * The text of one page with the values of this site filled in, before it is saved (and made safe) by {@see LegalPages::save()}.
     */
    public static function render(string $slug): string
    {
        $file = __DIR__.'/legal/'.$slug.'.html';

        if (! array_key_exists($slug, LegalPages::PAGES) || ! is_file($file)) {
            throw new InvalidArgumentException("There is no starting text for the legal page \"{$slug}\".");
        }

        return self::fill(str_replace("\r\n", "\n", (string) file_get_contents($file)), $slug);
    }

    /**
     * Put the values of this site where a text asks for them with {{a_name}}. A name that has no value is an error, so a typo in the
     * text cannot reach a page.
     */
    public static function fill(string $html, string $slug): string
    {
        $values = self::values();

        return (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', function (array $match) use ($values, $slug): string {
            if (! array_key_exists($match[1], $values)) {
                throw new RuntimeException('The starting text for "'.$slug.'" uses {{'.$match[1].'}}, which has no value.');
            }

            return e($values[$match[1]]);
        }, $html);
    }

    /**
     * The [Bracketed items] left in a text, in the order they first appear.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $html): array
    {
        preg_match_all('/\[[A-Z][^\[\]]*\]/', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * What the text says about this site, read from how it is set up. The lifetimes of the three browser-side cookies are in the
     * front end (useAppearance.ts, sidebar/utils.ts), and the remember-me cookie's is the framework's: a test keeps them true.
     *
     * @return array<string, string>
     */
    public static function values(): array
    {
        return [
            'site_name' => (string) config('seo.site_name'),
            'session_cookie' => (string) config('session.cookie'),
            'session_minutes' => (string) config('session.lifetime'),
            'consent_days' => (string) intdiv(CookieConsent::MINUTES, 60 * 24),
            'remember_days' => '400',
            'appearance_days' => '365',
            'sidebar_days' => '7',
            'search_retention' => self::retention((int) config('search.queries.retention_days', 90), 'day'),
            'usage_retention' => self::retention((int) config('billing.usage.retention_months', 13), 'month'),
        ];
    }

    /**
     * How long a kind of record is kept, as the end of a sentence. The prune jobs (PruneSearchQueryLogs, PruneUsageEvents) read the same
     * settings with the same defaults, and delete nothing at 0 or less, so then the text must not name a period: "0 days" would promise a
     * deletion that never happens.
     */
    private static function retention(int $amount, string $unit): string
    {
        if ($amount <= 0) {
            return 'no fixed period. They are not deleted automatically, so they are kept until we remove them.';
        }

        return $amount.' '.$unit.($amount === 1 ? '' : 's').'.';
    }

    /**
     * What the seeder says to the person who ran it. It is run with `db:seed`, or from another seeder, which is where the framework sets
     * the command it writes to.
     *
     * @param  list<array{0: 'info'|'line'|'warn', 1: string}>  $lines
     */
    private function report(array $lines): void
    {
        foreach ($lines as [$kind, $text]) {
            match ($kind) {
                'info' => $this->command->info($text),
                'warn' => $this->command->warn($text),
                'line' => $this->command->line($text),
            };
        }
    }

    /**
     * @param  list<string>  $slugs
     */
    private function titles(array $slugs): string
    {
        return implode(', ', array_map(fn (string $slug): string => (string) __(LegalPages::PAGES[$slug]), $slugs));
    }
}
