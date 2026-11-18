<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\DataErasureRequest;
use App\Models\DataExport;
use App\Models\PersonalAccessToken;
use App\Models\Poll;
use App\Models\User;
use App\Support\Localization\BrowserTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A time typed into an admin form is the admin's own: the browser turns what a `datetime-local` box holds (a wall clock, no zone) into
 * the moment it means and sends that, and the server keeps the moment. These tests run the site in a zone that is not UTC, because in
 * UTC a time kept as it was typed and a time kept as the moment it names are the same number and nothing can tell them apart
 * (docs/upgrading.md, "Times typed in the admin area").
 */
class AdminTimesTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/Denver';

    /** 16:00 UTC is 09:00 in Denver in March (MST, before daylight saving starts on the 14th). */
    private const MOMENT = '2027-03-10T16:00:00.000Z';

    private const WALL_CLOCK = '2027-03-10 09:00:00';

    private string $zoneBefore;

    protected function setUp(): void
    {
        parent::setUp();

        // The site's zone is the PHP default as well (the framework sets it from this at boot), so both are set.
        $this->zoneBefore = date_default_timezone_get();
        config(['app.timezone' => self::ZONE]);
        date_default_timezone_set(self::ZONE);
        Carbon::setTestNow(Carbon::parse('2027-03-01 08:00:00', self::ZONE));

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zoneBefore);

        parent::tearDown();
    }

    private function assertKeptAsTheMoment(string $table, int $id, string $column, ?string $moment = null): void
    {
        $moment ??= self::MOMENT;

        // What the database holds is the site's wall clock: 09:00 in Denver is what 16:00 UTC is called there.
        $this->assertSame(self::WALL_CLOCK, (string) DB::table($table)->where('id', $id)->value($column), "{$table}.{$column} as stored");

        // And what the site reads back is the same moment the admin chose.
        $model = match ($table) {
            'blogs' => Blog::query()->findOrFail($id),
            'polls' => Poll::query()->findOrFail($id),
            'personal_access_tokens' => PersonalAccessToken::query()->findOrFail($id),
            'data_exports' => DataExport::query()->findOrFail($id),
            'data_erasure_requests' => DataErasureRequest::query()->findOrFail($id),
        };

        $this->assertTrue(
            Carbon::parse($moment)->equalTo($model->{$column}),
            "{$table}.{$column} read back is {$model->{$column}?->toIso8601String()}, not {$moment}",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ways_to_name_the_moment(): array
    {
        return [
            'in UTC (what toISOString() sends)' => ['2027-03-10T16:00:00.000Z'],
            'with the offset of another zone' => ['2027-03-10T18:00:00+02:00'],
            'with the offset of the site' => ['2027-03-10T09:00:00-07:00'],
        ];
    }

    #[Test]
    #[DataProvider('ways_to_name_the_moment')]
    public function a_scheduled_post_is_kept_for_the_moment_the_admin_chose(string $moment): void
    {
        $this->post(route('acp.blogs.store'), [
            'title' => 'Scheduled for Denver morning',
            'body' => '<p>Hello</p>',
            'status' => 'scheduled',
            'scheduled_for' => $moment,
        ])->assertSessionHasNoErrors();

        $blog = Blog::query()->where('title', 'Scheduled for Denver morning')->firstOrFail();

        $this->assertSame('scheduled', $blog->status);
        $this->assertKeptAsTheMoment('blogs', $blog->id, 'scheduled_for');
    }

    #[Test]
    public function editing_a_scheduled_post_keeps_the_moment_and_saving_it_again_does_not_move_it(): void
    {
        $blog = Blog::factory()->create(['status' => 'draft', 'scheduled_for' => null, 'published_at' => null]);
        $payload = ['title' => $blog->title, 'body' => '<p>Hello</p>', 'status' => 'scheduled', 'scheduled_for' => self::MOMENT];

        $this->put(route('acp.blogs.update', $blog), $payload)->assertSessionHasNoErrors();
        $this->assertKeptAsTheMoment('blogs', $blog->id, 'scheduled_for');

        // What the edit page gives the form is an ISO date; the form sends the moment it holds back. Twice over, nothing drifts.
        $page = $this->get(route('acp.blogs.edit', $blog->fresh()))->viewData('page');
        $shown = $page['props']['blog']['scheduled_for'];

        $this->assertTrue(Carbon::parse(self::MOMENT)->equalTo(Carbon::parse($shown)), "The edit page says {$shown}");

        $this->put(route('acp.blogs.update', $blog), [...$payload, 'scheduled_for' => $shown])->assertSessionHasNoErrors();
        $this->put(route('acp.blogs.update', $blog), [...$payload, 'scheduled_for' => $shown])->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('blogs', $blog->id, 'scheduled_for');
    }

    #[Test]
    public function a_poll_opens_and_closes_at_the_moments_the_admin_chose(): void
    {
        $payload = [
            'title' => 'Which colour?',
            'status' => 'published',
            'options' => [['label' => 'Red'], ['label' => 'Blue']],
            'starts_at' => self::MOMENT,
            'ends_at' => '2027-03-11T16:00:00.000Z',
        ];

        $this->post(route('acp.polls.store'), $payload)->assertSessionHasNoErrors();

        $poll = Poll::query()->where('title', 'Which colour?')->firstOrFail();

        $this->assertKeptAsTheMoment('polls', $poll->id, 'starts_at');
        $this->assertSame('2027-03-11 09:00:00', (string) DB::table('polls')->where('id', $poll->id)->value('ends_at'));

        $this->put(route('acp.polls.update', $poll), [
            ...$payload,
            'options' => $poll->options()->get()->map(fn ($option) => ['id' => $option->id, 'label' => $option->label])->all(),
        ])->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('polls', $poll->id, 'starts_at');
        $this->assertSame('2027-03-11 09:00:00', (string) DB::table('polls')->where('id', $poll->id)->value('ends_at'));
    }

    #[Test]
    public function an_api_key_expires_at_the_moment_the_admin_chose(): void
    {
        $owner = User::factory()->create();

        $this->post(route('acp.tokens.store'), [
            'name' => 'Expires in Denver',
            'user_id' => $owner->id,
            'abilities' => ['profile:read'],
            'expires_at' => self::MOMENT,
        ])->assertSessionHasNoErrors();

        $token = PersonalAccessToken::query()->where('name', 'Expires in Denver')->firstOrFail();

        $this->assertKeptAsTheMoment('personal_access_tokens', $token->id, 'expires_at');

        $this->put(route('acp.tokens.update', $token), [
            'name' => 'Expires in Denver',
            'abilities' => ['profile:read'],
            'expires_at' => '2027-03-10T16:00:00+00:00',
        ])->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('personal_access_tokens', $token->id, 'expires_at');
    }

    #[Test]
    public function a_data_export_and_an_erasure_are_recorded_as_done_at_the_moments_the_admin_chose(): void
    {
        $export = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING, 'file_path' => null, 'failure_reason' => null]);

        $this->patch(route('acp.trust-safety.exports.update', $export), [
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'exports/export.zip',
            'failure_reason' => '',
            'completed_at' => self::MOMENT,
        ])->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('data_exports', $export->id, 'completed_at');

        $erasure = DataErasureRequest::factory()->create(['status' => DataErasureRequest::STATUS_PENDING]);

        $this->patch(route('acp.trust-safety.erasure.update', $erasure), [
            'status' => DataErasureRequest::STATUS_COMPLETED,
            'processed_at' => self::MOMENT,
        ])->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('data_erasure_requests', $erasure->id, 'processed_at');
    }

    #[Test]
    public function a_time_with_no_zone_is_still_read_in_the_sites_own_zone(): void
    {
        // What the forms sent before, and what a script that posts to the admin area may send: no change for it.
        $blog = Blog::factory()->create(['status' => 'draft', 'scheduled_for' => null, 'published_at' => null]);

        $this->put(route('acp.blogs.update', $blog), ['title' => $blog->title, 'body' => '<p>Hello</p>', 'status' => 'scheduled', 'scheduled_for' => '2027-03-10T09:00'])
            ->assertSessionHasNoErrors();

        $this->assertKeptAsTheMoment('blogs', $blog->id, 'scheduled_for');
    }

    #[Test]
    public function a_box_left_empty_keeps_no_time(): void
    {
        $export = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING, 'file_path' => null]);

        Carbon::setTestNow('2027-03-01 08:00:00');

        $this->patch(route('acp.trust-safety.exports.update', $export), [
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'exports/export.zip',
            'completed_at' => null,
        ])->assertSessionHasNoErrors();

        // Nothing typed means "now", as it did.
        $this->assertTrue(now()->equalTo($export->fresh()->completed_at));
    }

    #[Test]
    public function the_helper_turns_a_moment_into_the_sites_zone_and_nothing_into_nothing(): void
    {
        $this->assertNull(BrowserTime::parse(null));
        $this->assertNull(BrowserTime::parse(''));
        $this->assertNull(BrowserTime::parse('   '));

        foreach (['2027-03-10T16:00:00.000Z', '2027-03-10T18:00:00+02:00', '2027-03-10T09:00:00-07:00', '2027-03-10T09:00'] as $value) {
            $parsed = BrowserTime::parse($value);

            $this->assertSame(self::ZONE, $parsed->getTimezone()->getName(), $value);
            $this->assertSame(self::WALL_CLOCK, $parsed->format('Y-m-d H:i:s'), $value);
        }
    }
}
