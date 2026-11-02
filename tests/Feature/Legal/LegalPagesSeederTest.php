<?php

namespace Tests\Feature\Legal;

use App\Models\SystemSetting;
use App\Support\Legal\CookieConsent;
use App\Support\Legal\LegalPages;
use Carbon\Carbon;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The starting text for the three legal pages (docs/legal-pages.md): what the seeder does to a site, and that what the text says is
 * still what the software does.
 */
class LegalPagesSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = ['terms', 'privacy', 'cookies'];

    #[Test]
    public function it_adds_all_three_pages_and_they_are_on_the_site(): void
    {
        config(['seo.site_name' => 'Acme Forge']);

        $this->seed(LegalPagesSeeder::class);

        $this->assertSame(self::SLUGS, app(LegalPages::class)->publishedSlugs());

        foreach (self::SLUGS as $slug) {
            $this->get(route('legal.show', ['page' => $slug]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Legal')
                    ->where('slug', $slug)
                    ->where('body', fn (mixed $body) => is_string($body) && str_contains($body, 'Acme Forge') && ! str_contains($body, '{{'))
                    ->where('updated_at', fn (mixed $date) => is_string($date) && $date !== ''));
        }

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('legal.pages', self::SLUGS));
    }

    #[Test]
    public function a_page_that_already_has_text_is_left_alone(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {
            app(LegalPages::class)->save(['terms' => '<p>Our own terms.</p>', 'privacy' => null, 'cookies' => null]);

            Carbon::setTestNow('2026-03-01 12:00:00');

            $this->seed(LegalPagesSeeder::class);
        } finally {
            Carbon::setTestNow();
        }

        $legal = app(LegalPages::class);
        $stored = $this->stored();

        $this->assertSame('<p>Our own terms.</p>', $legal->body('terms'));
        $this->assertSame('2026-01-15', $legal->updatedAt('terms')?->toDateString());
        $this->assertStringContainsString('<h2>', (string) $legal->body('privacy'));
        $this->assertSame('2026-03-01', $legal->updatedAt('privacy')?->toDateString());
        $this->assertSame('2026-03-01', $legal->updatedAt('cookies')?->toDateString());
        $this->assertSame(self::SLUGS, array_keys($stored));
    }

    #[Test]
    public function running_it_again_changes_nothing_and_a_page_that_was_emptied_is_filled_again(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {
            $this->seed(LegalPagesSeeder::class);

            $legal = app(LegalPages::class);
            $before = $legal->stamp();
            $stored = $this->stored();

            Carbon::setTestNow('2026-03-01 12:00:00');

            $this->seed(LegalPagesSeeder::class);

            $this->assertSame($before, $legal->stamp());
            $this->assertEquals($stored, $this->stored());

            // The text of one page is cleared in the admin area; only that page gets its starting text back.
            $legal->save(['terms' => $legal->body('terms'), 'privacy' => $legal->body('privacy'), 'cookies' => null]);
            $this->assertSame(['terms', 'privacy'], $legal->publishedSlugs());

            $this->seed(LegalPagesSeeder::class);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(self::SLUGS, app(LegalPages::class)->publishedSlugs());
        $this->assertSame('2026-01-15', app(LegalPages::class)->updatedAt('terms')?->toDateString());
        $this->assertSame('2026-03-01', app(LegalPages::class)->updatedAt('cookies')?->toDateString());
    }

    #[Test]
    public function the_command_says_what_is_left_to_fill_in_and_that_a_second_run_changed_nothing(): void
    {
        $this->artisan('db:seed', ['--class' => LegalPagesSeeder::class])
            ->expectsOutputToContain('Legal pages: starting text added for Terms, Privacy policy, Cookie policy.')
            ->expectsOutputToContain('[Operator name]')
            ->expectsOutputToContain('[Record keeping period]')
            ->expectsOutputToContain('delete the note at the top of each page')
            ->assertSuccessful();

        $this->artisan('db:seed', ['--class' => LegalPagesSeeder::class])
            ->expectsOutputToContain('All three legal pages already have text. Nothing was changed.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_pages_keep_their_structure_and_every_word_when_they_are_saved(): void
    {
        $this->seed(LegalPagesSeeder::class);

        $legal = app(LegalPages::class);

        foreach (self::SLUGS as $slug) {
            $body = (string) $legal->body($slug);

            $this->assertSame($this->words(LegalPagesSeeder::render($slug)), $this->words($body), "The text of {$slug} changed when it was saved.");
            $this->assertStringContainsString('<blockquote>', $body);
            $this->assertStringContainsString('<h2>', $body);
            $this->assertStringContainsString('<a href="/legal/', $body);
        }

        $this->assertStringContainsString('<h3>Your account</h3>', (string) $legal->body('privacy'));
        $this->assertStringContainsString('<li><strong>cookie_consent</strong>', (string) $legal->body('cookies'));

        // A table is too narrow on a phone to read, so the text has none.
        foreach (self::SLUGS as $slug) {
            $this->assertStringNotContainsString('<table', (string) $legal->body($slug), $slug);
        }
    }

    #[Test]
    public function the_site_name_and_the_retention_periods_come_from_the_configuration_and_are_made_safe(): void
    {
        config([
            'seo.site_name' => 'Fish & Chips <b>',
            'search.queries.retention_days' => 45,
            'billing.usage.retention_months' => 6,
            'session.cookie' => 'forge-session',
            'session.lifetime' => 90,
        ]);

        $privacy = LegalPagesSeeder::render('privacy');
        $cookies = LegalPagesSeeder::render('cookies');

        $this->assertStringContainsString('Fish &amp; Chips &lt;b&gt;', $privacy);
        $this->assertStringNotContainsString('Fish & Chips <b>', $privacy);
        $this->assertStringContainsString('<strong>Searches:</strong> 45 days.', $privacy);
        $this->assertStringContainsString('<strong>API usage counts:</strong> 6 months.', $privacy);
        $this->assertStringContainsString('<li><strong>forge-session</strong> keeps you signed in', $cookies);
        $this->assertStringContainsString('90 minutes after your last visit', $cookies);
    }

    #[Test]
    public function every_bracketed_item_is_listed_and_every_listed_item_is_used(): void
    {
        $used = [];

        foreach (self::SLUGS as $slug) {
            $used = [...$used, ...LegalPagesSeeder::placeholdersIn(LegalPagesSeeder::render($slug))];
        }

        $this->assertEqualsCanonicalizing(array_keys(LegalPagesSeeder::PLACEHOLDERS), array_values(array_unique($used)));
    }

    #[Test]
    public function every_page_begins_with_the_note_that_it_is_a_starting_point(): void
    {
        foreach (self::SLUGS as $slug) {
            $html = LegalPagesSeeder::render($slug);

            $this->assertStringStartsWith("<blockquote>\n<p><strong>Starting text, not legal advice.</strong>", $html, $slug);
            $this->assertSame([], LegalPagesSeeder::placeholdersIn((string) strstr($html, '</blockquote>', true)), "The note in {$slug} names a bracketed item.");
        }
    }

    #[Test]
    public function every_value_the_text_asks_for_exists_and_a_missing_one_is_an_error(): void
    {
        foreach (self::SLUGS as $slug) {
            $this->assertStringNotContainsString('{{', LegalPagesSeeder::render($slug));
        }

        $this->expectException(InvalidArgumentException::class);

        LegalPagesSeeder::render('imprint');
    }

    #[Test]
    public function a_value_with_no_name_in_the_list_stops_the_seeder(): void
    {
        config(['seo.site_name' => 'Acme']);

        $this->assertSame('<p>Acme</p>', LegalPagesSeeder::fill('<p>{{site_name}}</p>', 'terms'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('{{nobody_knows}}');

        LegalPagesSeeder::fill('<p>{{nobody_knows}}</p>', 'terms');
    }

    #[Test]
    public function every_link_in_the_text_goes_to_a_page_that_exists(): void
    {
        $router = app(Router::class);
        $links = [];

        foreach (self::SLUGS as $slug) {
            preg_match_all('/href="([^"]+)"/', LegalPagesSeeder::render($slug), $found);
            $links = [...$links, ...$found[1]];
        }

        $this->assertNotEmpty($links);

        foreach (array_unique($links) as $href) {
            $this->assertStringStartsWith('/', $href, 'A link in the starting text is not on this site.');

            try {
                $router->getRoutes()->match(Request::create($href));
            } catch (NotFoundHttpException) {
                $this->fail("The starting text links to {$href}, which is not a page of this site.");
            }
        }
    }

    #[Test]
    public function the_cookie_policy_lists_what_the_software_sets_and_for_how_long(): void
    {
        $html = LegalPagesSeeder::render('cookies');
        $values = LegalPagesSeeder::values();

        // Names.
        foreach ([config('session.cookie'), 'XSRF-TOKEN', 'remember_web_', CookieConsent::NAME, 'appearance', 'sidebar_state'] as $name) {
            $this->assertStringContainsString('<li><strong>'.$name, $html, "{$name} is not in the cookie list.");
        }

        $this->assertStringContainsString('SIDEBAR_COOKIE_NAME = "sidebar_state"', $this->source('resources/js/components/ui/sidebar/utils.ts'));
        $this->assertStringContainsString("STORAGE_KEY = 'appearance'", $this->source('resources/js/composables/useAppearance.ts'));

        // Lifetimes.
        $this->assertSame('180', $values['consent_days']);
        $this->assertSame((string) intdiv($this->rememberMinutes(), 60 * 24), $values['remember_days']);
        $this->assertStringContainsString('days = 365', $this->source('resources/js/composables/useAppearance.ts'));
        $this->assertSame('365', $values['appearance_days']);
        $this->assertStringContainsString('SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7', $this->source('resources/js/components/ui/sidebar/utils.ts'));
        $this->assertSame('7', $values['sidebar_days']);

        // Nothing but these is written by the front end, and only when someone changes a setting.
        $this->assertSame(1, substr_count($this->source('resources/js/composables/useAppearance.ts'), 'setCookie(STORAGE_KEY'));
        $this->assertSame(1, substr_count($this->source('resources/js/components/ui/sidebar/SidebarProvider.vue'), 'document.cookie ='));
    }

    private function rememberMinutes(): int
    {
        $property = new ReflectionProperty(SessionGuard::class, 'rememberDuration');

        return (int) $property->getDefaultValue();
    }

    /**
     * The words of a page, with the markup, the line breaks and the way a quote mark is written taken out.
     *
     * @return list<string>
     */
    private function words(string $html): array
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_split('/\s+/u', trim($text)) ?: [];
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $value = SystemSetting::get(LegalPages::SETTING, []);

        return is_array($value) ? $value : [];
    }
}
