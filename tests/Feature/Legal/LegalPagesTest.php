<?php

namespace Tests\Feature\Legal;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Legal\CookieConsent;
use App\Support\Legal\LegalPages;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_empty_or_unknown_page_is_not_found(): void
    {
        $this->get(route('legal.show', ['page' => 'terms']))->assertNotFound();
        $this->get('/legal/nope')->assertNotFound();

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legal.pages', [])
            ->where('legal.consent', null));
    }

    #[Test]
    public function an_admin_publishes_a_page_and_a_script_is_removed(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->put(route('acp.legal.update'), [
            'terms' => '<p>Hello <script>alert(1)</script> <a href="javascript:alert(1)">click</a> <strong>there</strong></p>',
            'privacy' => '<p><a href="https://example.com/privacy">Privacy</a></p>',
            'cookies' => '<script>alert(1)</script>',
        ])->assertRedirect()->assertSessionHas('success', 'Legal pages saved.');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'legal.update',
            'outcome' => AuditLog::SUCCEEDED,
        ]);

        $this->actingAs($admin)->get(route('acp.legal.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/LegalPages')
            ->where('pages.terms', fn (mixed $body) => $this->safeHtml($body, 'Hello', 'there'))
            ->where('pages.privacy', fn (mixed $body) => is_string($body) && str_contains($body, 'https://example.com/privacy'))
            ->where('pages.cookies', ''));

        $this->get(route('legal.show', ['page' => 'terms']))
            ->assertOk()
            ->assertSee(e(route('legal.show', ['page' => 'terms'])), false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal')
                ->where('slug', 'terms')
                ->where('title', 'Terms')
                ->where('body', fn (mixed $body) => $this->safeHtml($body, 'Hello', 'there'))
                ->where('updated_at', fn (mixed $date) => is_string($date) && $date !== ''));

        $this->get(route('legal.show', ['page' => 'cookies']))->assertNotFound();

        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legal.pages', ['terms', 'privacy']));
    }

    #[Test]
    public function an_unchanged_page_keeps_its_date_and_clearing_it_unpublishes_it(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {
            $this->actingAs($admin)->put(route('acp.legal.update'), [
                'terms' => '<p>Hello</p>',
                'privacy' => '',
                'cookies' => '',
            ])->assertRedirect();

            $first = $this->stored()['terms']['updated_at'] ?? null;
            $this->assertIsString($first);

            Carbon::setTestNow('2026-03-01 12:00:00');

            $this->put(route('acp.legal.update'), [
                'terms' => '<p>Hello</p>',
                'privacy' => '<p>Private</p>',
                'cookies' => '',
            ])->assertRedirect();

            $stored = $this->stored();
            $this->assertSame($first, $stored['terms']['updated_at'] ?? null);
            $this->assertNotSame($first, $stored['privacy']['updated_at'] ?? null);

            $this->put(route('acp.legal.update'), [
                'terms' => '',
                'privacy' => '<p>Private</p>',
                'cookies' => '',
            ])->assertRedirect();
        } finally {
            Carbon::setTestNow();
        }

        $this->get(route('legal.show', ['page' => 'terms']))->assertNotFound();
        $this->get(route('legal.show', ['page' => 'privacy']))->assertOk();
        $this->assertArrayNotHasKey('terms', $this->stored());
    }

    #[Test]
    public function only_someone_who_can_edit_system_settings_can_save_the_pages(): void
    {
        $this->put(route('acp.legal.update'), $this->blank())->assertRedirect(route('login'));
        $this->get(route('acp.legal.edit'))->assertRedirect(route('login'));

        $member = User::factory()->create();
        $this->actingAs($member)->get(route('acp.legal.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('acp.legal.update'), $this->blank())->assertForbidden();

        $editor = User::factory()->create()->assignRole('editor');
        $this->actingAs($editor)->get(route('acp.legal.edit'))->assertForbidden();
        $this->actingAs($editor)->put(route('acp.legal.update'), $this->blank())->assertForbidden();

        $moderator = User::factory()->create()->assignRole('moderator');
        $moderator->givePermissionTo('system.acp.view');

        $this->actingAs($moderator)->get(route('acp.legal.edit'))->assertOk();
        $this->actingAs($moderator)->put(route('acp.legal.update'), [
            'terms' => '<p>No</p>',
            'privacy' => '',
            'cookies' => '',
        ])->assertForbidden();

        $this->assertSame([], app(LegalPages::class)->publishedSlugs());
    }

    #[Test]
    public function the_sitemap_lists_a_page_only_once_it_is_published(): void
    {
        Cache::flush();

        $loc = '<loc>'.e(route('legal.show', ['page' => 'terms'])).'</loc>';

        $this->get(route('sitemap'))->assertOk()->assertDontSee($loc, false);

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->put(route('acp.legal.update'), [
            'terms' => '<p>The terms.</p>',
            'privacy' => '',
            'cookies' => '',
        ])->assertRedirect();

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee($loc, false)
            ->assertDontSee('<loc>'.e(route('legal.show', ['page' => 'privacy'])).'</loc>', false);
    }

    #[Test]
    public function a_visitor_can_record_necessary_or_all(): void
    {
        $this->from(route('home'))
            ->post(route('legal.consent.store'), ['choice' => 'marketing'])
            ->assertSessionHasErrors('choice')
            ->assertCookieMissing(CookieConsent::NAME);

        $response = $this->from(route('home'))
            ->post(route('legal.consent.store'), ['choice' => CookieConsent::NECESSARY])
            ->assertRedirect(route('home'))
            ->assertCookie(CookieConsent::NAME, CookieConsent::NECESSARY);

        $cookie = $response->getCookie(CookieConsent::NAME);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $lifetime = $cookie->getExpiresTime() - time();
        $this->assertGreaterThan(170 * 24 * 60 * 60, $lifetime);
        $this->assertLessThan(190 * 24 * 60 * 60, $lifetime);

        $this->withCookie(CookieConsent::NAME, CookieConsent::NECESSARY)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('legal.consent', CookieConsent::NECESSARY));

        $this->assertFalse(CookieConsent::allowsOptional($this->requestWith(CookieConsent::NECESSARY)));
        $this->assertFalse(CookieConsent::allowsOptional($this->requestWith('marketing')));
        $this->assertTrue(CookieConsent::allowsOptional($this->requestWith(CookieConsent::ALL)));

        $this->withCookie(CookieConsent::NAME, 'marketing')
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('legal.consent', null));
    }

    #[Test]
    public function viewing_as_a_customer_does_not_record_a_choice(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $customer = User::factory()->create(['nickname' => 'Jane Customer']);

        $this->actingAs($admin)->put(route('acp.legal.update'), [
            'terms' => '<p>Hello</p>',
            'privacy' => '',
            'cookies' => '',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->withSession([Impersonation::SESSION_KEY => [
                'admin_id' => $admin->id,
                'user_id' => $customer->id,
                'reason' => 'Checking the banner',
                'started_at' => now()->getTimestamp(),
                'expires_at' => now()->addMinutes(30)->getTimestamp(),
                'views' => 0,
            ]])
            ->from(route('home'))
            ->post(route('legal.consent.store'), ['choice' => CookieConsent::ALL])
            ->assertCookieMissing(CookieConsent::NAME);

        $this->actingAs($admin)
            ->withSession([Impersonation::SESSION_KEY => [
                'admin_id' => $admin->id,
                'user_id' => $customer->id,
                'reason' => 'Checking the banner',
                'started_at' => now()->getTimestamp(),
                'expires_at' => now()->addMinutes(30)->getTimestamp(),
                'views' => 0,
            ]])
            ->get(route('legal.show', ['page' => 'terms']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legal.consent', null)
                ->where('impersonation.user.id', $customer->id));
    }

    #[Test]
    public function a_broken_setting_is_treated_as_empty(): void
    {
        SystemSetting::set(LegalPages::SETTING, 'broken');

        $this->get(route('legal.show', ['page' => 'terms']))->assertNotFound();
        $this->assertSame([], app(LegalPages::class)->publishedSlugs());

        SystemSetting::set(LegalPages::SETTING, ['terms' => 'just text']);

        $this->get(route('legal.show', ['page' => 'terms']))->assertNotFound();
    }

    private function safeHtml(mixed $body, string ...$needles): bool
    {
        if (! is_string($body)) {
            return false;
        }

        foreach ($needles as $needle) {
            if (! str_contains($body, $needle)) {
                return false;
            }
        }

        return ! str_contains($body, 'alert')
            && ! str_contains($body, '<script')
            && ! str_contains($body, 'javascript:');
    }

    /**
     * @return array{terms: string, privacy: string, cookies: string}
     */
    private function blank(): array
    {
        return ['terms' => '', 'privacy' => '', 'cookies' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $value = SystemSetting::get(LegalPages::SETTING, []);

        return is_array($value) ? $value : [];
    }

    private function requestWith(string $choice): Request
    {
        $request = Request::create('/');
        $request->cookies->set(CookieConsent::NAME, $choice);

        return $request;
    }
}
