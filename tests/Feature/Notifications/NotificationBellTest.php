<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Support\Members\DirectMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notification bell in the header: what it is sent, and what its buttons ask the server to do.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_notice_in_the_site_and_the_alert_for_the_browser_are_one_notification(): void
    {
        NotificationFacade::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        app(DirectMessages::class)->send($sender, $recipient, 'Hello');

        $sent = NotificationFacade::sent($recipient, DirectMessageReceived::class);
        $channels = $sent->flatMap(fn (DirectMessageReceived $notification): array => $notification->via($recipient))->unique()->values()->all();

        $this->assertCount(2, $sent);
        $this->assertContains('database', $channels);
        $this->assertContains('broadcast', $channels);
        $this->assertCount(1, $sent->pluck('id')->unique());
    }

    public function test_every_member_gets_a_notification_id_of_their_own(): void
    {
        $sender = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $message = app(DirectMessages::class)->send($sender, User::factory()->create(), 'Hi');

        NotificationFacade::fake();

        $notification = new DirectMessageReceived($sender, $message);

        // One notification object handed to two members, as a forum thread's followers are (ForumPublisher).
        $first->notifyThroughPreferences($notification, 'messages');
        $second->notifyThroughPreferences($notification, 'messages');

        $ids = NotificationFacade::sent($first, DirectMessageReceived::class)->pluck('id')
            ->merge(NotificationFacade::sent($second, DirectMessageReceived::class)->pluck('id'));

        $this->assertNull($notification->id);
        $this->assertCount(4, $ids);
        $this->assertCount(2, $ids->unique());
    }

    public function test_the_alert_pushed_to_the_browser_names_the_stored_notice(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        app(DirectMessages::class)->send($sender, $recipient, 'Hello');

        $stored = $recipient->notifications()->sole();

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($stored): bool {
            $pushed = $event->broadcastWith();

            // The bell reads the notice's fields at the top of the push (resources/js/lib/notifications.ts).
            return $pushed['id'] === $stored->id
                && $pushed['type'] === $stored->type
                && is_string($pushed['title'])
                && $pushed['title'] === $stored->data['title']
                && $pushed['url'] === $stored->data['url'];
        });
    }

    public function test_a_notice_with_nothing_to_queue_is_sent_once(): void
    {
        NotificationFacade::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $recipient->notificationSettings()->create([
            'category' => 'messages',
            'channel_mail' => false,
            'channel_push' => false,
            'channel_database' => true,
        ]);

        app(DirectMessages::class)->send($sender, $recipient, 'Hello');

        $sent = NotificationFacade::sent($recipient, DirectMessageReceived::class);

        $this->assertCount(1, $sent);
        $this->assertSame(['database'], $sent->first()->via($recipient));
    }

    public function test_a_member_marks_a_notice_as_read(): void
    {
        $member = User::factory()->create();
        $notice = $this->notice($member);

        $this->actingAs($member)
            ->from('/forum')
            ->post(route('notifications.read', $notice->id))
            ->assertStatus(303)
            ->assertRedirect('/forum');

        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_marking_a_notice_that_is_not_stored_is_not_an_error(): void
    {
        $member = User::factory()->create();
        $kept = $this->notice($member);

        // An alert that was only pushed to the browser, one dismissed in another tab, and an id that is not even a UUID.
        foreach ([(string) Str::uuid(), 'not-a-notification'] as $id) {
            $this->actingAs($member)
                ->from('/forum')
                ->post(route('notifications.read', $id))
                ->assertStatus(303)
                ->assertRedirect('/forum');
        }

        $this->assertNull($kept->fresh()->read_at);
    }

    public function test_a_notice_of_another_member_is_not_marked_and_not_revealed(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->notice($other);

        $this->actingAs($member)
            ->from('/forum')
            ->post(route('notifications.read', $theirs->id))
            ->assertStatus(303)
            ->assertRedirect('/forum');

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_member_dismisses_a_notice(): void
    {
        $member = User::factory()->create();
        $notice = $this->notice($member);
        $kept = $this->notice($member);

        $this->actingAs($member)
            ->from('/forum')
            ->delete(route('notifications.destroy', $notice->id))
            ->assertStatus(303)
            ->assertRedirect('/forum');

        $this->assertNull(DatabaseNotification::query()->find($notice->id));
        $this->assertNotNull(DatabaseNotification::query()->find($kept->id));
    }

    public function test_dismissing_a_notice_that_is_not_stored_or_not_theirs_is_not_an_error(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->notice($other);

        foreach ([(string) Str::uuid(), 'not-a-notification', $theirs->id] as $id) {
            $this->actingAs($member)
                ->from('/forum')
                ->delete(route('notifications.destroy', $id))
                ->assertStatus(303)
                ->assertRedirect('/forum');
        }

        $this->assertNotNull(DatabaseNotification::query()->find($theirs->id));
    }

    public function test_marking_everything_read_leaves_other_members_notices_alone(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->notice($member);
        $theirs = $this->notice($other);

        $this->actingAs($member)
            ->from('/forum')
            ->post(route('notifications.read-all'))
            ->assertStatus(303);

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_guest_cannot_use_the_bell(): void
    {
        $member = User::factory()->create();
        $notice = $this->notice($member);

        $this->post(route('notifications.read', $notice->id))->assertRedirect(route('login'));
        $this->delete(route('notifications.destroy', $notice->id))->assertRedirect(route('login'));

        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_the_page_lists_a_stored_notice_with_its_title_and_link(): void
    {
        $member = User::factory()->create();
        $notice = $this->notice($member);

        $this->actingAs($member)
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.unread_count', 1)
                ->where('notifications.items.0.id', $notice->id)
                ->where('notifications.items.0.title', 'New reply in "Something to Test"')
                ->where('notifications.items.0.url', '/forum/general/something-to-test'));
    }

    private function notice(User $member): DatabaseNotification
    {
        return $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ForumThreadUpdated',
            'data' => [
                'title' => 'New reply in "Something to Test"',
                'url' => '/forum/general/something-to-test',
                'thread_title' => 'Something to Test',
            ],
        ]);
    }
}
