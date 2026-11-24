<?php

namespace Tests\Feature\Housekeeping;

use App\Jobs\PruneReadNotifications;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A notification that has been read is kept for `notifications.read_retention_days` after it was read, and for ever if that is 0.
 * One that has not been read is never deleted.
 *
 * The bell in the header shows the notifications that have not been read, and no page lists one that has: a read notification is a row
 * nobody can see, and nothing deleted one.
 */
class ReadNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
    }

    /**
     * One notification for the member: when it was made and when it was read (null: not read), each as words Carbon reads ("3 days"),
     * counted back from now.
     *
     * @return string its id
     */
    private function notice(User $member, string $made, ?string $read = null): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\Example',
            'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id,
            'data' => json_encode(['title' => 'Example']),
            'read_at' => $read === null ? null : now()->sub($read),
            'created_at' => now()->sub($made),
            'updated_at' => now()->sub($made),
        ]);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function idsLeft(): array
    {
        return DB::table('notifications')->orderBy('created_at')->orderBy('id')->pluck('id')->all();
    }

    #[Test]
    public function a_read_notification_is_kept_thirty_days_unless_told_otherwise(): void
    {
        $this->assertSame(30, config('notifications.read_retention_days'));
    }

    #[Test]
    public function nothing_is_deleted_unless_a_retention_is_set(): void
    {
        $member = User::factory()->create();
        $this->notice($member, '10 years', '10 years');

        config(['notifications.read_retention_days' => 0]);
        (new PruneReadNotifications)->handle();

        config(['notifications.read_retention_days' => -30]);
        (new PruneReadNotifications)->handle();

        $this->assertSame(1, DB::table('notifications')->count());
    }

    #[Test]
    public function a_notification_read_longer_ago_than_the_retention_is_deleted_and_a_newer_one_is_kept(): void
    {
        config(['notifications.read_retention_days' => 30]);
        $member = User::factory()->create();

        $this->notice($member, '60 days', '31 days');
        $this->notice($member, '60 days', '30 days 1 second');
        $exactly = $this->notice($member, '60 days', '30 days');
        $inside = $this->notice($member, '60 days', '29 days');
        $today = $this->notice($member, '2 hours', '1 hour');

        (new PruneReadNotifications)->handle();

        $this->assertEqualsCanonicalizing([$exactly, $inside, $today], $this->idsLeft(), 'a notification read exactly as long ago as the retention is not older than it');
    }

    #[Test]
    public function a_notification_that_has_not_been_read_is_never_deleted_however_old(): void
    {
        config(['notifications.read_retention_days' => 1]);
        $member = User::factory()->create();

        $old = $this->notice($member, '10 years');
        $older = $this->notice($member, '40 years');

        (new PruneReadNotifications)->handle();

        $this->assertEqualsCanonicalizing([$old, $older], $this->idsLeft());
        $this->assertSame(2, $member->unreadNotifications()->count());
    }

    #[Test]
    public function the_retention_counts_from_when_it_was_read_not_from_when_it_was_made(): void
    {
        config(['notifications.read_retention_days' => 30]);
        $member = User::factory()->create();

        // Made a year ago and read an hour ago: the member has just seen it, and it is not theirs to lose tonight.
        $justRead = $this->notice($member, '1 year', '1 hour');
        // Made 50 days ago and read 40 days ago: read longer ago than the retention, so it goes, though it is the younger of the two.
        $this->notice($member, '50 days', '40 days');

        (new PruneReadNotifications)->handle();

        $this->assertSame([$justRead], $this->idsLeft());
    }

    #[Test]
    public function the_retention_is_counted_in_days(): void
    {
        config(['notifications.read_retention_days' => 2]);
        $member = User::factory()->create();

        $this->notice($member, '5 days', '49 hours');
        $inside = $this->notice($member, '5 days', '47 hours');

        (new PruneReadNotifications)->handle();

        $this->assertSame([$inside], $this->idsLeft());
    }

    #[Test]
    public function a_config_file_without_the_setting_still_keeps_thirty_days(): void
    {
        // A `config/` that a site copied before this setting existed has no `notifications.php` at all.
        config(['notifications' => Arr::except((array) config('notifications'), 'read_retention_days')]);
        $this->assertNull(config('notifications.read_retention_days'));
        $member = User::factory()->create();

        $this->notice($member, '90 days', '31 days');
        // Half a day inside 30 and half a day outside 29: a fallback of 29 days (or any other number) deletes it.
        $kept = $this->notice($member, '90 days', '29 days 12 hours');

        (new PruneReadNotifications)->handle();

        $this->assertSame([$kept], $this->idsLeft());
    }

    #[Test]
    public function a_large_backlog_is_all_deleted_not_just_the_first_chunk(): void
    {
        config(['notifications.read_retention_days' => 30]);
        $member = User::factory()->create();
        $old = now()->subDays(100)->toDateTimeString();

        $rows = array_map(fn (int $i) => [
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Example',
            'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id,
            'data' => '{}',
            'read_at' => $old,
            'created_at' => $old,
            'updated_at' => $old,
        ], range(1, 5300));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        $read = $this->notice($member, '2 days', '1 day');
        $unread = $this->notice($member, '200 days');

        (new PruneReadNotifications)->handle();

        $this->assertEqualsCanonicalizing([$read, $unread], $this->idsLeft(), 'more than one chunk (5,000) of old rows was not all deleted');
    }

    #[Test]
    public function every_member_loses_only_what_they_have_read_and_the_bell_is_what_it_was(): void
    {
        config(['notifications.read_retention_days' => 30]);
        $reader = User::factory()->create();
        $other = User::factory()->create();

        $this->notice($reader, '90 days', '60 days');
        $this->notice($reader, '90 days', '40 days');
        $this->notice($other, '90 days', '35 days');
        $unreadOld = $this->notice($reader, '80 days');
        $unreadNew = $this->notice($reader, '1 hour');
        $otherUnread = $this->notice($other, '70 days');

        (new PruneReadNotifications)->handle();

        $this->assertEqualsCanonicalizing([$unreadOld, $unreadNew, $otherUnread], $this->idsLeft());

        $this->actingAs($reader)
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.unread_count', 2)
                ->where('notifications.items.0.id', $unreadNew)
                ->where('notifications.items.1.id', $unreadOld));
    }

    #[Test]
    public function the_job_is_scheduled_every_night_after_the_failed_jobs(): void
    {
        $events = $this->events('PruneReadNotifications');

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * *', $events->first()->expression);
    }

    /**
     * @return Collection<int, Event>
     */
    private function events(string $needle)
    {
        // The schedule is put together when the console kernel is booted, which a command does.
        $this->artisan('schedule:list')->assertSuccessful();

        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->description, $needle) || str_contains((string) $event->command, $needle) || str_contains($event->getSummaryForDisplay(), $needle))
            ->values();
    }
}
