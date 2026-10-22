<?php

namespace Tests\Feature\Usage;

use App\Models\User;
use App\Support\Usage\Usage;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GivesUsageAllowance;
use Tests\TestCase;

/**
 * What a customer sees of their own usage on the billing page, and what staff see of everybody's in the control panel.
 */
class UsageScreensTest extends TestCase
{
    use GivesUsageAllowance;
    use RefreshDatabase;

    private Usage $usage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        $this->usage = app(Usage::class);
    }

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return tap(User::factory()->create())->givePermissionTo('billing.acp.view');
    }

    // --- The billing page -----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_billing_page_shows_what_the_account_has_used_and_may_use(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1000);
        $this->usage->record($user, 'api-calls', 412);
        $this->usage->record(User::factory()->create(), 'api-calls', 99);

        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/Billing')
            ->has('usage', 1)
            ->where('usage.0.meter', 'api-calls')
            ->where('usage.0.label', 'API calls')
            ->where('usage.0.used', 412)
            ->where('usage.0.allowance', 1000)
            ->where('usage.0.remaining', 588)
            ->where('usage.0.exhausted', false)
            ->where('usage.0.blocks', false)
            ->where('usage.0.resets_at', '2026-11-01T00:00:00+00:00'));
    }

    #[Test]
    public function an_account_with_no_limit_is_shown_what_it_used_and_no_allowance(): void
    {
        $user = User::factory()->create();
        $this->usage->record($user, 'api-calls', 7);

        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.0.used', 7)
            ->where('usage.0.allowance', null)
            ->where('usage.0.remaining', null));
    }

    #[Test]
    public function a_site_with_no_meters_shows_no_usage(): void
    {
        config(['billing.usage.meters' => []]);

        $this->actingAs(User::factory()->create())->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page->where('usage', []));
    }

    #[Test]
    public function the_reload_after_subscribing_carries_the_new_plans_allowance(): void
    {
        $user = User::factory()->create();

        // What the page asks for once a subscription has started: a partial reload of just these props, which keeps all the others (so a
        // stale allowance would keep showing "no limit" to somebody whose new plan has one).
        $version = null;
        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(function (Assert $page) use (&$version) {
            $version = $page->toArray()['version'];

            $page->where('usage.0.allowance', null);
        });

        $this->onAPlanGiving($user, 1000);

        $this->actingAs($user->fresh())->get(route('settings.billing.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'settings/Billing',
            'X-Inertia-Partial-Data' => 'subscription,invoices,trial_eligible,portal,usage',
        ])
            ->assertOk()
            ->assertJsonPath('props.usage.0.allowance', 1000)
            ->assertJsonMissingPath('props.plans');
    }

    // --- The control panel ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_report_adds_up_the_month_for_everybody(): void
    {
        $heavy = User::factory()->create(['nickname' => 'Heavy']);
        $light = User::factory()->create(['nickname' => 'Light']);
        $this->onAPlanGiving($heavy, 10);

        $this->usage->record($heavy, 'api-calls', 6, at: CarbonImmutable::parse('2026-10-01 09:00:00'));
        $this->usage->record($heavy, 'api-calls', 4, at: CarbonImmutable::parse('2026-10-03 09:00:00'));
        $this->usage->record($light, 'api-calls', 3, at: CarbonImmutable::parse('2026-10-03 18:00:00'));
        // Another month, and another meter: not in this one.
        $this->usage->record($light, 'api-calls', 50, at: CarbonImmutable::parse('2026-09-30 23:59:59'));

        $this->actingAs($this->staff())->get(route('acp.billing.usage.index'))->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingUsage')
            ->where('meter', 'api-calls')
            ->where('month', '2026-10')
            ->where('totals', ['units' => 13, 'events' => 3, 'accounts' => 2])
            ->has('daily', 31)
            ->where('daily.0', ['date' => '2026-10-01', 'units' => 6])
            ->where('daily.1', ['date' => '2026-10-02', 'units' => 0])
            ->where('daily.2', ['date' => '2026-10-03', 'units' => 7])
            ->where('daily.30.date', '2026-10-31')
            ->has('top', 2)
            ->where('top.0.user.nickname', 'Heavy')
            ->where('top.0.units', 10)
            ->where('top.0.allowance', 10)
            ->where('top.0.exhausted', true)
            ->where('top.1.user.nickname', 'Light')
            ->where('top.1.units', 3)
            ->where('top.1.allowance', null)
            ->where('top.1.exhausted', false));
    }

    #[Test]
    public function an_earlier_month_is_shown_without_saying_what_an_account_may_use_now(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 10);
        $this->usage->record($user, 'api-calls', 12, at: CarbonImmutable::parse('2026-09-10 10:00:00'));

        $this->actingAs($this->staff())->get(route('acp.billing.usage.index', ['month' => '2026-09']))->assertInertia(fn (Assert $page) => $page
            ->where('month', '2026-09')
            ->where('totals.units', 12)
            ->has('daily', 30)
            ->where('top.0.allowance', null)
            ->where('top.0.exhausted', false));
    }

    #[Test]
    public function something_that_is_not_a_month_or_a_meter_is_the_default(): void
    {
        $this->usage->record(User::factory()->create(), 'api-calls', 2);
        $staff = $this->staff();

        foreach (['not-a-month', '2026-13', '2026-1', '', '../../etc'] as $month) {
            $this->actingAs($staff)->get(route('acp.billing.usage.index', ['month' => $month, 'meter' => 'no-such-meter']))
                ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10')->where('meter', 'api-calls')->where('totals.units', 2));
        }
    }

    #[Test]
    public function the_month_picker_offers_the_last_twelve_months_newest_first(): void
    {
        $this->actingAs($this->staff())->get(route('acp.billing.usage.index'))->assertInertia(fn (Assert $page) => $page
            ->has('months', 12)
            ->where('months.0', ['value' => '2026-10', 'label' => 'October 2026'])
            ->where('months.1', ['value' => '2026-09', 'label' => 'September 2026'])
            ->where('months.11', ['value' => '2025-11', 'label' => 'November 2025'])
            ->where('retention_months', 13));
    }

    #[Test]
    public function a_busy_month_lists_only_the_heaviest_twenty_five_accounts_most_first(): void
    {
        foreach (range(1, 27) as $n) {
            $this->usage->record(User::factory()->create(), 'api-calls', $n);
        }

        $this->actingAs($this->staff())->get(route('acp.billing.usage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.accounts', 27)
            ->has('top', 25)
            ->where('top.0.units', 27)
            ->where('top.24.units', 3));
    }

    #[Test]
    public function a_month_with_no_use_is_an_empty_report_and_not_an_error(): void
    {
        $this->actingAs($this->staff())->get(route('acp.billing.usage.index', ['month' => '2026-02']))->assertInertia(fn (Assert $page) => $page
            ->where('totals', ['units' => 0, 'events' => 0, 'accounts' => 0])
            ->has('daily', 28)
            ->where('top', []));
    }

    #[Test]
    public function a_site_with_no_meters_gets_an_empty_report(): void
    {
        config(['billing.usage.meters' => []]);

        $this->actingAs($this->staff())->get(route('acp.billing.usage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('meters', [])
            ->where('meter', null)
            ->where('daily', [])
            ->where('top', []));
    }

    #[Test]
    public function a_second_meter_can_be_looked_at_on_its_own(): void
    {
        config(['billing.usage.meters.exports' => ['label' => 'Exports', 'unit' => 'export']]);
        $user = User::factory()->create();
        $this->usage->record($user, 'api-calls', 4);
        $this->usage->record($user, 'exports', 9);

        $this->actingAs($this->staff())->get(route('acp.billing.usage.index', ['meter' => 'exports']))->assertInertia(fn (Assert $page) => $page
            ->where('meter', 'exports')
            ->has('meters', 2)
            ->where('totals.units', 9));
    }

    #[Test]
    public function only_staff_with_the_billing_permission_see_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->get(route('acp.billing.usage.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.billing.usage.index'))->assertForbidden();
    }
}
