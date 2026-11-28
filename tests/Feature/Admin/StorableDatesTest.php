<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\Blog;
use App\Models\Coupon;
use App\Models\DataErasureRequest;
use App\Models\DataExport;
use App\Models\GiftCard;
use App\Models\ModerationAction;
use App\Models\PersonalAccessToken;
use App\Models\Poll;
use App\Models\SearchQuery;
use App\Models\SupportTicket;
use App\Models\TokenLog;
use App\Models\User;
use App\Rules\StorableDate;
use App\Support\Database\TimestampRange;
use App\Support\Moderation\Moderation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * A date typed in the admin area has to be one the database can keep. MySQL and MariaDB keep 1970 to 2038 in a `timestamp` column and
 * answered anything else with a 500, a year of five digits did on every database (and was saved before it failed on SQLite and PostgreSQL,
 * so the list that showed it stopped opening), and PostgreSQL refused some dates that only filtered a list (docs/upgrading.md, "Dates the
 * database cannot keep"). SQLite keeps anything, so what these look for is the refusal and the date that reaches the query.
 */
class StorableDatesTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSED = 'Choose a date from 1971 to 2037.';

    private const TOO_EARLY = ['1970-12-31T23:59:59Z', '1969-12-31', '1900-01-01', '@-99999999999'];

    private const TOO_LATE = ['2038-01-01T00:00:00Z', '2099-06-15', '+010000-01-01T00:00:00.000Z', '2027-01-01 +100000 years'];

    private const FIRST = '1971-01-01T00:00:00Z';

    private const LAST = '2037-12-31T23:59:59Z';

    private User $admin;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($this->admin);
    }

    /**
     * Every box in the admin area whose date is saved in a `timestamp` column: how to send it, where it ends up, and whether it takes a
     * date before today and one after.
     *
     * @return array<string, array{table: string, column: string, past: bool, future: bool, send: callable(string): array{TestResponse, int|null}}>
     */
    private function boxes(): array
    {
        $blog = fn (string $date) => ['title' => 'Post '.++$this->made, 'body' => '<p>Hello</p>', 'status' => 'scheduled', 'scheduled_for' => $date];
        $poll = fn (string $field, string $date) => ['title' => 'Poll '.++$this->made, 'status' => 'draft', 'options' => [['label' => 'Yes'], ['label' => 'No']], $field => $date];
        $coupon = fn (string $field, string $date) => ['code' => 'CODE'.++$this->made, 'type' => 'percent', 'value' => '10', 'is_active' => true, $field => $date];
        $token = fn (string $date) => ['name' => 'Key '.++$this->made, 'abilities' => ['profile:read'], 'expires_at' => $date];

        $boxes = [
            'a new scheduled post' => ['blogs', 'scheduled_for', false, true, fn (string $date) => [$this->post(route('acp.blogs.store'), $blog($date)), null]],
            'a post being rescheduled' => ['blogs', 'scheduled_for', false, true, function (string $date) use ($blog) {
                $post = Blog::factory()->create(['status' => 'draft', 'scheduled_for' => null, 'published_at' => null]);

                return [$this->put(route('acp.blogs.update', $post), $blog($date)), $post->id];
            }],
            'a new gift card' => ['gift_cards', 'expires_at', false, true, fn (string $date) => [$this->post(route('acp.commerce.gift-cards.store'), ['amount' => '10', 'expires_at' => $date]), null]],
            'a gift card' => ['gift_cards', 'expires_at', true, true, function (string $date) {
                $card = GiftCard::factory()->create(['expires_at' => null]);

                return [$this->patch(route('acp.commerce.gift-cards.update', $card), ['is_active' => true, 'expires_at' => $date]), $card->id];
            }],
            'a new API key' => ['personal_access_tokens', 'expires_at', false, true, fn (string $date) => [$this->post(route('acp.tokens.store'), [...$token($date), 'user_id' => $this->admin->id]), null]],
            'an API key' => ['personal_access_tokens', 'expires_at', true, true, function (string $date) use ($token) {
                $key = $this->admin->createToken('Existing', ['profile:read'])->accessToken;

                return [$this->put(route('acp.tokens.update', $key), $token($date)), $key->id];
            }],
            'an erasure request' => ['data_erasure_requests', 'processed_at', true, true, function (string $date) {
                $erasure = DataErasureRequest::factory()->create(['status' => DataErasureRequest::STATUS_PENDING, 'processed_at' => null]);

                return [$this->patch(route('acp.trust-safety.erasure.update', $erasure), ['status' => DataErasureRequest::STATUS_COMPLETED, 'processed_at' => $date]), $erasure->id];
            }],
            'a data export' => ['data_exports', 'completed_at', true, false, function (string $date) {
                $export = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING, 'completed_at' => null]);

                return [$this->patch(route('acp.trust-safety.exports.update', $export), [
                    'status' => DataExport::STATUS_COMPLETED,
                    'file_path' => DataExport::filePrefix($export->user_id).'1.zip',
                    'completed_at' => $date,
                ]), $export->id];
            }],
        ];

        foreach (['starts_at', 'ends_at'] as $field) {
            $boxes["a new poll's {$field}"] = ['polls', $field, true, true, fn (string $date) => [$this->post(route('acp.polls.store'), $poll($field, $date)), null]];
            $boxes["a poll's {$field}"] = ['polls', $field, true, true, function (string $date) use ($poll, $field) {
                $existing = Poll::factory()->create(['starts_at' => null, 'ends_at' => null]);

                return [$this->put(route('acp.polls.update', $existing), $poll($field, $date)), $existing->id];
            }];
            $boxes["a new discount code's {$field}"] = ['coupons', $field, true, true, fn (string $date) => [$this->post(route('acp.commerce.coupons.store'), $coupon($field, $date)), null]];
            $boxes["a discount code's {$field}"] = ['coupons', $field, true, true, function (string $date) use ($coupon, $field) {
                $existing = Coupon::factory()->create(['starts_at' => null, 'ends_at' => null]);

                return [$this->put(route('acp.commerce.coupons.update', $existing), $coupon($field, $date)), $existing->id];
            }];
        }

        return array_map(fn (array $box) => array_combine(['table', 'column', 'past', 'future', 'send'], $box), $boxes);
    }

    #[Test]
    public function every_box_refuses_a_date_the_database_cannot_keep_and_saves_nothing(): void
    {
        foreach ($this->boxes() as $name => $box) {
            // A box that only takes a date to come (or only one gone by) refuses the other end anyway, and says why as well.
            foreach ([...self::TOO_EARLY, ...self::TOO_LATE] as $date) {
                $rows = DB::table($box['table'])->count();

                [$response, $id] = ($box['send'])($date);

                $response->assertSessionHasErrors([$box['column'] => self::REFUSED], null, 'default');
                $this->assertSame($id === null ? $rows : $rows + 1, DB::table($box['table'])->count(), "{$name}: {$date}");

                if ($id !== null) {
                    $this->assertNull(DB::table($box['table'])->where('id', $id)->value($box['column']), "{$name}: {$date}");
                }

                $this->flushSession();
                $this->actingAs($this->admin);
            }
        }
    }

    #[Test]
    public function every_box_saves_the_first_and_the_last_date_of_the_range(): void
    {
        foreach ($this->boxes() as $name => $box) {
            foreach (array_filter([self::FIRST => $box['past'], self::LAST => $box['future']]) as $date => $unused) {
                [$response, $id] = ($box['send'])($date);

                $response->assertSessionHasNoErrors();

                $stored = (string) DB::table($box['table'])->when($id, fn ($query) => $query->where('id', $id))->orderByDesc('id')->value($box['column']);
                // A gift card lasts to the end of the day that was chosen.
                $expected = $box['table'] === 'gift_cards' ? substr($date, 0, 10).' 23:59:59' : Carbon::parse($date)->toDateTimeString();

                $this->assertSame($expected, $stored, "{$name}: {$date}");
                $this->flushSession();
                $this->actingAs($this->admin);
            }
        }
    }

    #[Test]
    public function the_range_is_of_the_time_as_the_site_keeps_it(): void
    {
        $zone = date_default_timezone_get();
        config(['app.timezone' => 'America/Denver']);
        date_default_timezone_set('America/Denver');

        try {
            $send = fn (string $date) => $this->post(route('acp.polls.store'), ['title' => 'Poll '.++$this->made, 'status' => 'draft', 'options' => [['label' => 'Yes'], ['label' => 'No']], 'starts_at' => $date]);

            // In UTC these two name 2038 and 1971; on the clock of a site in Denver they are 2037 and 1970.
            $send('2038-01-01T05:00:00Z')->assertSessionHasNoErrors();
            $this->assertSame('2037-12-31 22:00:00', (string) DB::table('polls')->orderByDesc('id')->value('starts_at'));

            $send('1971-01-01T05:00:00Z')->assertSessionHasErrors(['starts_at' => self::REFUSED]);
            $send('2038-01-01T07:00:00Z')->assertSessionHasErrors(['starts_at' => self::REFUSED]);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    #[Test]
    public function what_is_not_a_date_is_only_said_not_to_be_one(): void
    {
        foreach (['soon', ['2027-01-01'], 12] as $value) {
            $errors = Validator::make(['when' => $value], ['when' => ['nullable', 'date', new StorableDate]])->errors();

            $this->assertSame(['The when field must be a valid date.'], $errors->get('when'), var_export($value, true));
        }

        foreach ([null, ''] as $value) {
            $this->assertTrue(Validator::make(['when' => $value], ['when' => ['nullable', 'date', new StorableDate]])->passes());
        }
    }

    #[Test]
    public function the_range_holds_whole_years_and_brings_a_moment_past_it_to_its_end(): void
    {
        foreach (['1971-01-01 00:00:00', '2000-02-29 12:34:56', '2037-12-31 23:59:59'] as $inside) {
            $moment = Carbon::parse($inside);

            $this->assertTrue(TimestampRange::holds($moment), $inside);
            $this->assertSame($inside, TimestampRange::nearest($moment)->toDateTimeString());
        }

        foreach (['1970-12-31 23:59:59' => '1971-01-01 00:00:00', '0001-01-01 00:00:00' => '1971-01-01 00:00:00', '2038-01-01 00:00:00' => '2037-12-31 23:59:59', '9999-06-15 08:00:00' => '2037-12-31 23:59:59'] as $outside => $end) {
            $moment = Carbon::parse($outside);

            $this->assertFalse(TimestampRange::holds($moment), $outside);
            $this->assertSame($end, TimestampRange::nearest($moment)->toDateTimeString(), $outside);
            // What was given is left as it was.
            $this->assertSame($outside, $moment->toDateTimeString());
        }

        $this->assertInstanceOf(CarbonImmutable::class, TimestampRange::nearest(CarbonImmutable::parse('1900-01-01')));
    }

    #[Test]
    public function a_ban_cannot_end_so_late_that_the_sites_zone_has_no_year_for_it(): void
    {
        // West of the site, the end of the last day of 9999 is in the year 10000 on the site's clock.
        foreach (['Pacific/Pago_Pago', 'UTC'] as $zone) {
            $this->admin->forceFill(['timezone' => $zone])->save();
            $member = User::factory()->create();

            $this->put(route('acp.users.ban', $member), ['reason' => 'Spamming the forum', 'until' => '9999-12-31', 'notify' => false])
                ->assertSessionHasErrors(['until' => Moderation::TOO_FAR_OFF]);
            $this->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$member->id], 'reason' => 'Spamming the forum', 'until' => '9999-12-31', 'notify' => false])
                ->assertSessionHasErrors(['until' => Moderation::TOO_FAR_OFF]);

            $this->assertFalse((bool) $member->fresh()->is_banned, $zone);
            $this->assertSame(0, ModerationAction::query()->where('user_id', $member->id)->count(), $zone);
            $this->flushSession();
            $this->actingAs($this->admin);
        }
    }

    #[Test]
    public function a_ban_can_end_on_any_day_before_that_one(): void
    {
        $this->admin->forceFill(['timezone' => 'Pacific/Pago_Pago'])->save();

        foreach (['9999-12-30' => '9999-12-31 10:59:59', '2050-06-15' => '2050-06-16 10:59:59'] as $until => $stored) {
            $one = User::factory()->create();
            $other = User::factory()->create();

            $this->put(route('acp.users.ban', $one), ['reason' => 'Spamming the forum', 'until' => $until, 'notify' => false])->assertSessionHasNoErrors();
            $this->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$other->id], 'reason' => 'Spamming the forum', 'until' => $until, 'notify' => false])
                ->assertSessionHasNoErrors();

            foreach ([$one, $other] as $member) {
                $this->assertTrue((bool) $member->fresh()->is_banned, $until);
                $this->assertSame($stored, (string) DB::table('users')->where('id', $member->id)->value('banned_until'), $until);
            }
        }
    }

    /**
     * The values a query was given while the page was made, with a date as the text the database is sent.
     *
     * @return array{TestResponse, list<mixed>}
     */
    private function asked(string $url): array
    {
        $values = [];
        $listening = true;

        DB::listen(function (QueryExecuted $query) use (&$values, &$listening) {
            if ($listening) {
                foreach ($query->bindings as $value) {
                    $values[] = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
                }
            }
        });

        $response = $this->get($url);

        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }

        $listening = false;

        return [$response, $values];
    }

    #[Test]
    public function a_list_filtered_by_a_date_past_the_range_is_asked_for_the_end_of_it(): void
    {
        $start = '1971-01-01 00:00:00';
        $end = '2037-12-31 23:59:59';
        $filters = [
            // route, the names of its two filters
            ['acp.search-analytics.index', 'date_from', 'date_to'],
            ['acp.search-analytics.export-searches', 'date_from', 'date_to'],
            ['acp.support.index', 'date_from', 'date_to'],
            ['acp.tokens.index', 'date_from', 'date_to'],
        ];

        foreach ($filters as [$route, $from, $to]) {
            foreach ([$from => ['00:00:00'], $to => ['23:59:59']] as $field => [$time]) {
                foreach (['1900-01-01' => $start, '@-99999999999' => $start, '2050-06-15' => $end, '2027-01-01 +100000 years' => $end, '2027-03-10' => "2027-03-10 {$time}"] as $typed => $sent) {
                    [$response, $values] = $this->asked(route($route, [$field => $typed]));

                    $response->assertOk();
                    $this->assertContains($sent, $values, "{$route} {$field}={$typed}");
                }
            }
        }
    }

    #[Test]
    public function the_audit_log_brings_a_day_past_the_range_into_it_after_changing_zone(): void
    {
        // Before 1892 Pago Pago kept the other side of the date line's time, so the first day of year 1 there began in the year 0 in UTC.
        foreach (['UTC', 'Pacific/Pago_Pago', 'Pacific/Kiritimati'] as $zone) {
            $this->admin->forceFill(['timezone' => $zone])->save();

            foreach (['from', 'to'] as $field) {
                foreach (['0000-01-01' => '1971-01-01 00:00:00', '0001-01-01' => '1971-01-01 00:00:00', '9999-12-31' => '2037-12-31 23:59:59'] as $typed => $sent) {
                    [$response, $values] = $this->asked(route('acp.audit-log.index', [$field => $typed]));

                    $response->assertOk();
                    $this->assertContains($sent, $values, "{$zone} {$field}={$typed}");
                }
            }
        }
    }

    #[Test]
    public function invoices_are_asked_for_by_a_day_the_database_can_read(): void
    {
        foreach (['date_from', 'date_to'] as $field) {
            foreach (['January 1st, 2027' => '2027-01-01', '2027-01-01 +1 day' => '2027-01-02', '1900-01-01' => '1971-01-01', '@99999999999' => '2037-12-31', '+010000-01-01T00:00:00.000Z' => '2037-12-31'] as $typed => $sent) {
                [$response, $values] = $this->asked(route('acp.billing.invoices.index', [$field => $typed]));

                $response->assertOk()->assertSessionHasNoErrors();
                $this->assertContains($sent, $values, "{$field}={$typed}");
            }
        }
    }

    #[Test]
    public function a_date_past_the_range_finds_what_the_date_asked_for_would(): void
    {
        BillingInvoice::factory()->create();
        SearchQuery::factory()->create(['term' => 'lanterns']);
        SupportTicket::factory()->create();
        AuditLog::factory()->create();
        $key = $this->admin->createToken('Logged')->accessToken;
        $this->assertInstanceOf(PersonalAccessToken::class, $key);
        $log = new TokenLog(['personal_access_token_id' => $key->id, 'token_name' => 'Logged', 'route' => '/api/example', 'method' => 'GET', 'status' => 'success', 'http_status' => 200]);
        $log->save();

        $lists = [
            ['acp.billing.invoices.index', 'date_from', 'date_to', 'invoices.data'],
            ['acp.search-analytics.index', 'date_from', 'date_to', 'recentSearches.data'],
            ['acp.support.index', 'date_from', 'date_to', 'tickets.data'],
            ['acp.tokens.index', 'date_from', 'date_to', 'tokenLogs.data'],
            ['acp.audit-log.index', 'from', 'to', 'entries.data'],
        ];

        foreach ($lists as [$route, $from, $to, $rows]) {
            foreach ([[$from, '1900-01-01', 1], [$to, '1900-01-01', 0], [$from, '2099-01-01', 0], [$to, '2099-01-01', 1]] as [$field, $date, $count]) {
                $page = $this->get(route($route, [$field => $date]))->assertOk()->viewData('page');

                $this->assertCount($count, data_get($page['props'], $rows), "{$route} {$field}={$date}");
            }
        }

        $this->assertStringContainsString('lanterns', $this->get(route('acp.search-analytics.export-searches', ['date_from' => '1900-01-01']))->streamedContent());
        $this->assertStringNotContainsString('lanterns', $this->get(route('acp.search-analytics.export-searches', ['date_to' => '1900-01-01']))->streamedContent());
    }

    /**
     * What the databases disagree about, read by each of them: this is the test that CI's MySQL and PostgreSQL runs are for.
     */
    #[Test]
    public function no_odd_date_typed_in_the_admin_area_is_a_server_error(): void
    {
        $odd = [
            '0000-01-01', '0001-01-01', '-0001-01-01', '0999-12-31', '9999-12-31', '32767-01-01', '@99999999999', '@-99999999999',
            '+010000-01-01T00:00:00.000Z', '2027-01-01 +100000 years', 'January 1st, 2027', '2027-01-01 00:00:00.5 America/Denver', 'soon',
        ];
        $lists = [
            'acp.billing.invoices.index' => ['date_from', 'date_to'],
            'acp.search-analytics.index' => ['date_from', 'date_to'],
            'acp.search-analytics.export-searches' => ['date_from', 'date_to'],
            'acp.support.index' => ['date_from', 'date_to'],
            'acp.tokens.index' => ['date_from', 'date_to'],
            'acp.audit-log.index' => ['from', 'to'],
            'acp.billing.usage.index' => ['month'],
        ];
        $member = User::factory()->create();

        foreach ($odd as $date) {
            $answers = [];

            foreach ($this->boxes() as $name => $box) {
                $answers["{$name}: {$date}"] = ($box['send'])($date)[0];
            }

            foreach ($lists as $route => $fields) {
                foreach ($fields as $field) {
                    $answers["{$route} {$field}={$date}"] = $this->get(route($route, [$field => $date]));
                }
            }

            $answers["a ban until {$date}"] = $this->put(route('acp.users.ban', $member), ['reason' => 'Spamming the forum', 'until' => $date, 'notify' => false]);

            foreach ($answers as $what => $response) {
                if ($response->baseResponse instanceof StreamedResponse) {
                    $response->streamedContent();
                }

                $this->assertLessThan(500, $response->getStatusCode(), $what);
            }

            $this->flushSession();
            $this->actingAs($this->admin);
        }

        // Nothing was saved that the site cannot show again.
        foreach (['acp.blogs.index', 'acp.polls.index', 'acp.commerce.coupons.index', 'acp.commerce.gift-cards.index', 'acp.tokens.index', 'acp.trust-safety.index', 'acp.users.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    #[Test]
    public function a_month_of_usage_that_nothing_can_have_been_kept_for_is_this_month(): void
    {
        foreach (['0000-01' => '2026-11', '0001-01' => '2026-11', '1970-12' => '2026-11', '2038-01' => '2026-11', '9999-12' => '2026-11', '1971-01' => '1971-01', '2037-12' => '2037-12', '2026-10' => '2026-10'] as $typed => $shown) {
            $page = $this->get(route('acp.billing.usage.index', ['month' => $typed]))->assertOk()->viewData('page');

            $this->assertSame($shown, $page['props']['month'], $typed);
        }
    }
}
