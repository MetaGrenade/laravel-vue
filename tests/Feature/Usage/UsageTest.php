<?php

namespace Tests\Feature\Usage;

use App\Events\UsageRecorded;
use App\Jobs\PruneUsageEvents;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\Meter;
use App\Support\Usage\Meters;
use App\Support\Usage\Usage;
use App\Support\Usage\UsagePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GivesUsageAllowance;
use Tests\TestCase;

/**
 * Metered usage: counting a use into the ledger, what an account has used this month, and what it may use.
 */
class UsageTest extends TestCase
{
    use GivesUsageAllowance;
    use RefreshDatabase;

    private Usage $usage;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed moment in the middle of a month, so that "this month" cannot change under a test.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));

        $this->usage = app(Usage::class);
    }

    // --- Counting -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_use_is_counted_and_added_up_for_the_month(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->usage->record($user, 'api-calls'));
        $this->assertTrue($this->usage->record($user, 'api-calls', 4));

        $this->assertSame(5, $this->usage->used($user, 'api-calls'));
        $this->assertDatabaseHas('usage_events', ['user_id' => $user->id, 'meter' => 'api-calls', 'quantity' => 4]);
    }

    #[Test]
    public function only_this_accounts_uses_of_this_meter_in_this_month_are_added_up(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        config(['billing.usage.meters.exports' => ['label' => 'Exports', 'unit' => 'export']]);

        $this->usage->record($user, 'api-calls', 2);
        $this->usage->record($other, 'api-calls', 7);
        $this->usage->record($user, 'exports', 5);

        // The last second of last month, and the first of next month, are other months.
        $this->usage->record($user, 'api-calls', 100, at: CarbonImmutable::parse('2026-09-30 23:59:59'));
        $this->usage->record($user, 'api-calls', 200, at: CarbonImmutable::parse('2026-11-01 00:00:00'));
        // The first second of this month is in it.
        $this->usage->record($user, 'api-calls', 3, at: CarbonImmutable::parse('2026-10-01 00:00:00'));

        $this->assertSame(5, $this->usage->used($user, 'api-calls'));
        $this->assertSame(100, $this->usage->used($user, 'api-calls', UsagePeriod::monthOf(CarbonImmutable::parse('2026-09-10'))));
        $this->assertSame(5, $this->usage->used($user, 'exports'));
    }

    #[Test]
    public function a_use_with_a_key_is_counted_once_however_often_it_is_reported(): void
    {
        Event::fake([UsageRecorded::class]);
        $user = User::factory()->create();

        $this->assertTrue($this->usage->record($user, 'api-calls', 2, key: 'req-1'));
        $this->assertFalse($this->usage->record($user, 'api-calls', 2, key: 'req-1'));

        $this->assertSame(2, $this->usage->used($user, 'api-calls'));
        Event::assertDispatchedTimes(UsageRecorded::class, 1);
    }

    #[Test]
    public function a_key_belongs_to_one_account_and_one_meter_and_uses_with_no_key_are_all_counted(): void
    {
        config(['billing.usage.meters.exports' => ['label' => 'Exports', 'unit' => 'export']]);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($this->usage->record($user, 'api-calls', key: 'same'));
        $this->assertTrue($this->usage->record($other, 'api-calls', key: 'same'));
        $this->assertTrue($this->usage->record($user, 'exports', key: 'same'));

        $this->assertTrue($this->usage->record($user, 'api-calls'));
        $this->assertTrue($this->usage->record($user, 'api-calls'));
        $this->assertSame(3, $this->usage->used($user, 'api-calls'));
    }

    #[Test]
    public function a_use_that_cannot_be_counted_is_a_mistake_in_the_code(): void
    {
        $user = User::factory()->create();

        foreach ([
            fn () => $this->usage->record($user, 'no-such-meter'),
            fn () => $this->usage->record($user, 'api-calls', 0),
            fn () => $this->usage->record($user, 'api-calls', -3),
            fn () => $this->usage->record($user, 'api-calls', key: str_repeat('k', 101)),
            fn () => $this->usage->used($user, 'no-such-meter'),
            fn () => $this->usage->allowance($user, 'no-such-meter'),
        ] as $mistake) {
            try {
                $mistake();
                $this->fail('A use that cannot be counted was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_ledger_event_says_what_was_counted_and_for_whom(): void
    {
        Event::fake([UsageRecorded::class]);
        $user = User::factory()->create();

        $this->usage->record($user, 'api-calls', 3, key: 'k1', at: CarbonImmutable::parse('2026-10-10 08:00:00'), tokenId: 9);

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $event) => $event->user->is($user)
            && $event->meter === 'api-calls'
            && $event->quantity === 3
            && $event->idempotencyKey === 'k1'
            && $event->occurredAt->toDateTimeString() === '2026-10-10 08:00:00');
        $this->assertDatabaseHas('usage_events', ['user_id' => $user->id, 'token_id' => 9]);
    }

    // --- What an account may use ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_account_with_no_plan_allowance_has_the_free_allowance_or_no_limit(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->usage->allowance($user, 'api-calls'));

        config(['billing.usage.meters.api-calls.free_allowance' => 100]);
        $this->assertSame(100, $this->usage->allowance($user, 'api-calls'));
    }

    #[Test]
    public function what_a_plan_gives_is_the_allowance_and_beats_the_free_one(): void
    {
        config(['billing.usage.meters.api-calls.free_allowance' => 100]);
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1000);

        $this->assertSame(1000, $this->usage->allowance($user, 'api-calls'));
    }

    #[Test]
    public function working_out_what_an_account_may_use_goes_through_its_entitlements_once(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1000);

        // Each pass over an account's entitlements reads its subscriptions once; this is asked on every API request.
        $subscriptionReads = function (callable $work): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $work();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->filter(fn (array $query) => preg_match('/from [`"]?subscriptions[`"]? /', $query['query']) === 1)->count();
        };

        $this->assertSame(1, $subscriptionReads(fn () => $this->usage->allowance($user, 'api-calls')));
        $this->assertSame(1, $subscriptionReads(fn () => $this->usage->status($user, 'api-calls')));
    }

    #[Test]
    public function two_plans_giving_it_add_up_as_every_limit_does(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1000);
        $this->onAPlanGiving($user, 500);

        $this->assertSame(1500, $this->usage->allowance($user, 'api-calls'));
    }

    #[Test]
    public function a_meter_with_no_entitlement_has_only_its_free_allowance(): void
    {
        config(['billing.usage.meters.api-calls.entitlement' => null, 'billing.usage.meters.api-calls.free_allowance' => 5]);
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1000);

        $this->assertSame(5, $this->usage->allowance($user, 'api-calls'));
    }

    #[Test]
    public function the_status_says_what_is_left_and_whether_the_meter_refuses_more(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 10);

        $this->usage->record($user, 'api-calls', 4);
        $status = $this->usage->status($user, 'api-calls');
        $this->assertSame([4, 10, 6, false, false], [$status->used, $status->allowance, $status->remaining(), $status->exhausted(), $status->blocks()]);

        $this->usage->record($user, 'api-calls', 6);
        $status = $this->usage->status($user, 'api-calls');
        $this->assertSame([10, 0, true, true], [$status->used, $status->remaining(), $status->exhausted(), $status->blocks()]);

        // Past it, still counted.
        $this->usage->record($user, 'api-calls', 5);
        $this->assertSame(0, $this->usage->status($user, 'api-calls')->remaining());
        $this->assertSame(15, $this->usage->status($user, 'api-calls')->used);
    }

    #[Test]
    public function a_meter_that_allows_overage_is_used_up_but_never_refuses(): void
    {
        config(['billing.usage.meters.api-calls.overage' => 'allow']);
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 3);
        $this->usage->record($user, 'api-calls', 3);

        $status = $this->usage->status($user, 'api-calls');
        $this->assertTrue($status->exhausted());
        $this->assertFalse($status->blocks());
    }

    #[Test]
    public function with_no_limit_nothing_is_ever_used_up(): void
    {
        $user = User::factory()->create();
        $this->usage->record($user, 'api-calls', 100000);

        $status = $this->usage->status($user, 'api-calls');
        $this->assertNull($status->allowance);
        $this->assertNull($status->remaining());
        $this->assertFalse($status->exhausted());
        $this->assertFalse($status->blocks());
    }

    #[Test]
    public function the_summary_is_what_a_page_shows(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 10);
        $this->usage->record($user, 'api-calls', 4);

        $this->assertSame([[
            'meter' => 'api-calls',
            'label' => 'API calls',
            'unit' => 'call',
            'used' => 4,
            'allowance' => 10,
            'remaining' => 6,
            'exhausted' => false,
            'blocks' => false,
            'resets_at' => '2026-11-01T00:00:00+00:00',
        ]], $this->usage->summary($user));
    }

    // --- The meters and the month ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_meters_come_from_the_config(): void
    {
        config(['billing.usage.meters' => [
            'a' => ['label' => 'A things', 'unit' => 'thing', 'entitlement' => 'a-things', 'free_allowance' => '12', 'overage' => 'allow'],
            'b' => [],
            'c' => ['free_allowance' => '', 'overage' => 'nonsense', 'entitlement' => ''],
            'd' => ['free_allowance' => -4],
        ]]);

        $meters = app(Meters::class)->all();

        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($meters));
        $this->assertEquals(new Meter('a', 'A things', 'thing', 'a-things', 12, Meter::ALLOW), $meters['a']);
        $this->assertEquals(new Meter('b', 'b', 'use', null, null, Meter::BLOCK), $meters['b']);
        $this->assertEquals(new Meter('c', 'c', 'use', null, null, Meter::BLOCK), $meters['c'], 'an empty or unknown setting is no setting');
        $this->assertSame(0, $meters['d']->freeAllowance, 'a negative allowance is none');
    }

    #[Test]
    public function the_month_runs_from_its_first_moment_to_the_first_moment_of_the_next(): void
    {
        $month = UsagePeriod::monthOf(CarbonImmutable::parse('2026-12-31 23:59:59'));

        $this->assertSame('2026-12-01 00:00:00', $month->start->toDateTimeString());
        $this->assertSame('2027-01-01 00:00:00', $month->end->toDateTimeString());
        $this->assertTrue($month->contains(CarbonImmutable::parse('2026-12-01 00:00:00')));
        $this->assertTrue($month->contains(CarbonImmutable::parse('2026-12-31 23:59:59')));
        $this->assertFalse($month->contains(CarbonImmutable::parse('2027-01-01 00:00:00')));
        $this->assertFalse($month->contains(CarbonImmutable::parse('2026-11-30 23:59:59')));
        $this->assertSame('2026-11-01 00:00:00', $month->previous()->start->toDateTimeString());
        $this->assertSame('2026-10-01', UsagePeriod::monthOf()->start->toDateString(), 'now, when no moment is given');
    }

    // --- Keeping the ledger from growing for ever -----------------------------------------------------------------------------------------

    #[Test]
    public function events_older_than_the_retention_are_deleted_whole_months_at_a_time(): void
    {
        config(['billing.usage.retention_months' => 3]);
        $user = User::factory()->create();

        // "Now" is 15 October: three months back from the start of October is the start of July.
        foreach (['2026-06-30 23:59:59' => 'gone', '2026-07-01 00:00:00' => 'kept', '2026-07-20 10:00:00' => 'kept', '2026-10-14 10:00:00' => 'kept'] as $when => $fate) {
            $this->usage->record($user, 'api-calls', 1, key: $when.' '.$fate, at: CarbonImmutable::parse($when));
        }

        (new PruneUsageEvents)->handle();

        $this->assertSame(3, UsageEvent::query()->count());
        $this->assertSame(0, UsageEvent::query()->where('idempotency_key', 'like', '% gone')->count());
    }

    #[Test]
    public function a_retention_of_nothing_keeps_everything(): void
    {
        config(['billing.usage.retention_months' => 0]);
        $this->usage->record(User::factory()->create(), 'api-calls', at: CarbonImmutable::parse('2020-01-01'));

        (new PruneUsageEvents)->handle();

        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function the_pruning_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('PruneUsageEvents')->assertSuccessful();
    }

    #[Test]
    public function the_events_of_a_deleted_account_go_with_it(): void
    {
        $user = User::factory()->create();
        $this->usage->record($user, 'api-calls', 3);
        $this->usage->record(User::factory()->create(), 'api-calls', 1);

        $user->delete();

        $this->assertSame(1, UsageEvent::query()->count());
    }
}
