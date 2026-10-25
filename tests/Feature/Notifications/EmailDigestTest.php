<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendEmailDigests;
use App\Models\EmailDigestItem;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\DirectMessageReceived;
use App\Support\Members\DirectMessages;
use App\Support\Notifications\EmailDigest;
use App\Support\Notifications\RecordsForDigest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A daily email of community updates (docs/email-digests.md).
 */
class EmailDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_digest_holds_the_email_until_the_morning_job(): void
    {
        NotificationFacade::fake();

        $sender = User::factory()->create(['nickname' => 'Ada']);
        $recipient = User::factory()->create(['nickname' => 'Grace']);

        app(DirectMessages::class)->send($sender, $recipient, 'Hello now');

        $this->assertTrue(
            NotificationFacade::sent($recipient, DirectMessageReceived::class)
                ->contains(fn (DirectMessageReceived $notification): bool => in_array('mail', $notification->via($recipient), true)),
        );
        $this->assertSame(0, EmailDigestItem::query()->count());

        NotificationFacade::fake();

        $recipient->notificationSettings()->create([
            'category' => 'messages',
            'channel_mail' => true,
            'channel_push' => true,
            'channel_database' => true,
            'daily_digest' => true,
        ]);
        $recipient->unsetRelation('notificationSettings');

        app(DirectMessages::class)->send($sender, $recipient, 'Hello later');

        $sent = NotificationFacade::sent($recipient, DirectMessageReceived::class);
        $this->assertTrue($sent->contains(fn (DirectMessageReceived $notification): bool => in_array('database', $notification->via($recipient), true)));
        $this->assertFalse($sent->contains(fn (DirectMessageReceived $notification): bool => in_array('mail', $notification->via($recipient), true)));
        $this->assertSame(1, EmailDigestItem::query()->count());

        (new SendEmailDigests)->handle(app(EmailDigest::class));

        $digest = NotificationFacade::sent($recipient, DailyDigest::class)->first();
        $this->assertInstanceOf(DailyDigest::class, $digest);
        $html = (string) $digest->toMail($recipient)->render();
        $this->assertStringContainsString($sender->nickname.' sent you a message.', $html);
        $this->assertStringContainsString(route('messages.show', $sender), $html);
        $this->assertSame(0, EmailDigestItem::query()->count());
    }

    public function test_the_morning_email_lists_the_latest_lines_and_keeps_their_text_as_text(): void
    {
        NotificationFacade::fake();

        $recipient = User::factory()->create();
        $total = DailyDigest::SHOWN + 1;

        for ($number = 1; $number <= $total; $number++) {
            EmailDigestItem::query()->create([
                'user_id' => $recipient->id,
                'category' => 'forums',
                'title' => sprintf('Line %02d', $number),
                'excerpt' => null,
                'url' => route('forum.index'),
            ]);
        }

        EmailDigestItem::query()->create([
            'user_id' => $recipient->id,
            'category' => 'messages',
            'title' => 'Hello [click](https://evil.test)',
            'excerpt' => 'A note',
            'url' => route('messages.index'),
        ]);

        (new SendEmailDigests)->handle(app(EmailDigest::class));

        $digest = NotificationFacade::sent($recipient, DailyDigest::class)->first();
        $this->assertInstanceOf(DailyDigest::class, $digest);
        $html = (string) $digest->toMail($recipient)->render();

        $this->assertStringContainsString('Forum updates', $html);
        $this->assertStringContainsString('Direct messages', $html);
        $this->assertStringNotContainsString('Line 01', $html);
        $this->assertStringContainsString('Line 02', $html);
        $this->assertStringContainsString(sprintf('Line %02d', $total), $html);
        $this->assertStringContainsString('And 1 earlier update.', $html);
        $this->assertStringNotContainsString('href="https://evil.test"', $html);
        $this->assertStringContainsString(route('messages.index'), $html);
        $this->assertSame(0, EmailDigestItem::query()->count());
    }

    public function test_waiting_lines_are_dropped_when_they_should_not_be_sent(): void
    {
        $recipient = User::factory()->create();

        $recipient->notificationSettings()->create([
            'category' => 'messages',
            'channel_mail' => false,
            'channel_push' => true,
            'channel_database' => true,
            'daily_digest' => true,
        ]);

        EmailDigestItem::query()->create([
            'user_id' => $recipient->id,
            'category' => 'messages',
            'title' => 'Mail is off',
            'excerpt' => null,
            'url' => route('messages.index'),
        ]);

        NotificationFacade::fake();
        (new SendEmailDigests)->handle(app(EmailDigest::class));
        NotificationFacade::assertNothingSent();
        $this->assertSame(0, EmailDigestItem::query()->count());

        $banned = User::factory()->create(['is_banned' => true]);
        EmailDigestItem::query()->create([
            'user_id' => $banned->id,
            'category' => 'forums',
            'title' => 'Banned',
            'excerpt' => null,
            'url' => route('forum.index'),
        ]);

        (new SendEmailDigests)->handle(app(EmailDigest::class));
        NotificationFacade::assertNothingSent();
        $this->assertSame(0, EmailDigestItem::query()->count());

        $unverified = User::factory()->create(['email_verified_at' => null]);
        $stale = EmailDigestItem::query()->create([
            'user_id' => $unverified->id,
            'category' => 'blogs',
            'title' => 'Too old',
            'excerpt' => null,
            'url' => route('blogs.index'),
        ]);
        $stale->forceFill(['created_at' => now()->subDays(15)])->save();
        $fresh = EmailDigestItem::query()->create([
            'user_id' => $unverified->id,
            'category' => 'blogs',
            'title' => 'Still waiting',
            'excerpt' => null,
            'url' => route('blogs.index'),
        ]);

        (new SendEmailDigests)->handle(app(EmailDigest::class));
        $this->assertModelMissing($stale);
        $this->assertModelExists($fresh);
        NotificationFacade::assertNothingSent();

        $fresh->delete();

        $recipient->notificationSettings()->where('category', 'messages')->update([
            'channel_mail' => true,
            'daily_digest' => true,
        ]);

        for ($number = 1; $number <= EmailDigest::PER_CATEGORY; $number++) {
            EmailDigestItem::query()->create([
                'user_id' => $recipient->id,
                'category' => 'messages',
                'title' => 'Kept '.$number,
                'excerpt' => null,
                'url' => route('messages.index'),
            ]);
        }

        $sender = User::factory()->create();
        app(DirectMessages::class)->send($sender, $recipient, 'Past the hundred');

        $this->assertSame(EmailDigest::PER_CATEGORY, EmailDigestItem::query()->where('user_id', $recipient->id)->count());
        $this->assertFalse(
            NotificationFacade::sent($recipient, DirectMessageReceived::class)
                ->contains(fn (DirectMessageReceived $notification): bool => in_array('mail', $notification->via($recipient), true)),
        );

        $recipient->notificationSettings()->create([
            'category' => 'support',
            'channel_mail' => true,
            'channel_push' => true,
            'channel_database' => true,
            'daily_digest' => true,
        ]);

        NotificationFacade::fake();
        $recipient->notifyThroughPreferences(new OffSiteNotice('https://evil.test/phish'), 'support', ['mail']);
        $this->assertSame(EmailDigest::PER_CATEGORY, EmailDigestItem::query()->count());
        NotificationFacade::assertSentTo($recipient, OffSiteNotice::class);

        NotificationFacade::fake();
        $recipient->notifyThroughPreferences(new OffSiteNotice('https://evil.test/phish'), 'messages', ['mail', 'database']);
        $this->assertSame(EmailDigest::PER_CATEGORY, EmailDigestItem::query()->count());
        NotificationFacade::assertSentTo($recipient, OffSiteNotice::class, function (OffSiteNotice $notification) use ($recipient): bool {
            return in_array('mail', $notification->via($recipient), true);
        });

        $gone = User::factory()->create();
        EmailDigestItem::query()->create([
            'user_id' => $gone->id,
            'category' => 'forums',
            'title' => 'Goes with the account',
            'excerpt' => null,
            'url' => route('forum.index'),
        ]);
        $gone->delete();
        $this->assertSame(0, EmailDigestItem::query()->where('user_id', $gone->id)->count());
    }

    public function test_a_run_that_loses_the_claim_does_not_send_those_lines(): void
    {
        NotificationFacade::fake();

        $recipient = User::factory()->create();
        $item = EmailDigestItem::query()->create([
            'user_id' => $recipient->id,
            'category' => 'forums',
            'title' => 'Still waiting',
            'excerpt' => null,
            'url' => route('forum.index'),
        ]);

        $steal = true;
        $connection = DB::connection();
        $connection->beforeExecuting(function (string $query) use (&$steal, $recipient): void {
            if (! $steal || ! str_contains(strtolower($query), 'delete') || ! str_contains($query, 'email_digest_items')) {
                return;
            }

            $steal = false;
            EmailDigestItem::query()->where('user_id', $recipient->id)->delete();
        });

        try {
            (new SendEmailDigests)->handle(app(EmailDigest::class));

            NotificationFacade::assertNothingSent();
            $this->assertModelExists($item);

            (new SendEmailDigests)->handle(app(EmailDigest::class));
        } finally {
            $callbacks = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
            $registered = $callbacks->getValue($connection);
            array_pop($registered);
            $callbacks->setValue($connection, $registered);
        }

        NotificationFacade::assertSentToTimes($recipient, DailyDigest::class, 1);
        $this->assertModelMissing($item);
    }

    public function test_the_choice_is_saved_from_the_notification_settings(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->put(route('settings.notifications.update'), [
                'preferences' => $this->preferences([
                    'forums' => ['daily_digest' => true, 'push' => false],
                    'messages' => ['daily_digest' => true, 'mail' => false],
                ]),
            ])
            ->assertRedirect(route('settings.notifications.edit'));

        $this->assertDatabaseHas('user_notification_settings', [
            'user_id' => $member->id,
            'category' => 'forums',
            'daily_digest' => true,
            'channel_push' => false,
            'channel_mail' => true,
        ]);
        $this->assertDatabaseHas('user_notification_settings', [
            'user_id' => $member->id,
            'category' => 'messages',
            'daily_digest' => true,
            'channel_mail' => false,
        ]);
        $this->assertDatabaseHas('user_notification_settings', [
            'user_id' => $member->id,
            'category' => 'support',
            'daily_digest' => false,
        ]);

        $member->unsetRelation('notificationSettings');

        $this->actingAs($member)
            ->get(route('settings.notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('categories', function (mixed $categories): bool {
                $rows = collect($categories);
                $forums = $rows->firstWhere('key', 'forums');
                $support = $rows->firstWhere('key', 'support');

                if (! is_array($forums) || ! is_array($support)) {
                    return false;
                }

                $forumDigest = collect($forums['options'] ?? [])->firstWhere('key', 'daily_digest');
                $supportDigest = collect($support['options'] ?? [])->firstWhere('key', 'daily_digest');

                return is_array($forumDigest)
                    && ($forumDigest['enabled'] ?? null) === true
                    && $supportDigest === null;
            }));
    }

    public function test_the_job_runs_every_morning(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->description, 'SendEmailDigests'));

        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * *', $events->first()->expression);
    }

    /**
     * @param  array<string, array<string, bool>>  $overrides
     * @return array<string, array<string, bool>>
     */
    private function preferences(array $overrides = []): array
    {
        $preferences = [];

        foreach (array_keys((array) config('notification-preferences.categories')) as $category) {
            $preferences[$category] = [
                'mail' => true,
                'push' => true,
                'database' => true,
            ];

            if ($category === 'support') {
                $preferences[$category]['team_notifications'] = true;
            }
        }

        foreach ($overrides as $category => $values) {
            $preferences[$category] = array_merge($preferences[$category], $values);
        }

        return $preferences;
    }
}

/**
 * A notice whose address is chosen by the test, so a digest can refuse one that leaves the site.
 */
class OffSiteNotice extends Notification implements RecordsForDigest
{
    public function __construct(private string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Look',
            'excerpt' => 'Outside',
            'url' => $this->url,
        ];
    }
}
