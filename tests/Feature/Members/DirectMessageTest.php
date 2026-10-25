<?php

namespace Tests\Feature\Members;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\ForumIgnore;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Support\Members\DirectMessages;
use App\Support\WebsiteSections;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Private messages between two members (docs/direct-messages.md).
 */
class DirectMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_two_members_can_exchange_a_message(): void
    {
        Notification::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->actingAs($sender)
            ->get(route('members.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('message.own', false)
                ->where('message.can', true));

        $this->actingAs($sender)
            ->get(route('members.show', $sender))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('message.own', true)
                ->where('message.can', false));

        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), [
                'body' => "<p>Hello</p>\n<b>there</b>",
            ])
            ->assertRedirect(route('messages.show', $recipient));

        $this->assertSame(1, DirectConversation::query()->count());
        $this->assertSame('Hello'."\n".'there', DirectMessage::query()->value('body'));

        Notification::assertSentTo($recipient, DirectMessageReceived::class, function (DirectMessageReceived $notification) use ($sender, $recipient): bool {
            $url = route('messages.show', $sender);

            return $notification->toArray($recipient)['url'] === $url
                && $notification->toMail($recipient)->actionUrl === $url;
        });
        Notification::assertNotSentTo($sender, DirectMessageReceived::class);

        $this->actingAs($recipient)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.member.nickname', $sender->nickname)
                ->where('conversations.data.0.excerpt', 'Hello there')
                ->where('conversations.data.0.unread', true));

        $this->actingAs($recipient)
            ->get(route('messages.show', $sender))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.body', "Hello\nthere")
                ->where('messages.data.0.mine', false)
                ->where('blocked', null));

        $this->actingAs($recipient)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.data.0.unread', false));

        $this->actingAs($recipient)
            ->post(route('messages.store', $sender), ['body' => 'Back at you'])
            ->assertRedirect(route('messages.show', $sender));

        $this->assertSame(1, DirectConversation::query()->count());
        $this->assertSame(2, DirectMessage::query()->count());
        Notification::assertSentTo($sender, DirectMessageReceived::class);

        $this->actingAs($sender)
            ->get(route('settings.notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'categories',
                fn (mixed $categories): bool => collect($categories)->contains(
                    fn (mixed $category): bool => is_array($category) && ($category['key'] ?? null) === 'messages',
                ),
            ));

        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), ['body' => '   '])
            ->assertSessionHasErrors('body');
        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), ['body' => '<p></p>'])
            ->assertSessionHasErrors('body');
        $this->assertSame(2, DirectMessage::query()->count());

        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);

        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), ['body' => 'Still here'])
            ->assertRedirect(route('messages.show', $recipient));

        $this->actingAs($sender)
            ->get(route('members.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('message.can', true)
                ->where('follow', null)
                ->where('ignore', null));
    }

    public function test_a_long_conversation_opens_on_its_latest_page(): void
    {
        Notification::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $messages = app(DirectMessages::class);
        $last = DirectMessages::TRANSCRIPT_PER_PAGE + 1;

        for ($number = 1; $number <= $last; $number++) {
            $messages->send($sender, $recipient, 'Note '.$number);
        }

        $this->actingAs($recipient)
            ->get(route('messages.show', $sender))
            ->assertRedirect(route('messages.show', ['user' => $sender, 'page' => 2]));

        $this->actingAs($recipient)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.data.0.unread', false));

        $this->actingAs($recipient)
            ->get(route('messages.show', ['user' => $sender, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.body', 'Note '.$last));

        $this->actingAs($recipient)
            ->get(route('messages.show', ['user' => $sender, 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages.data', DirectMessages::TRANSCRIPT_PER_PAGE)
                ->where('messages.data.0.body', 'Note 1')
                ->where('messages.data.'.(DirectMessages::TRANSCRIPT_PER_PAGE - 1).'.body', 'Note '.DirectMessages::TRANSCRIPT_PER_PAGE));
    }

    public function test_an_ignore_stops_messages_and_a_moderator_can_still_be_reached(): void
    {
        Notification::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), ['body' => 'Before'])
            ->assertRedirect();

        $told = Notification::sent($recipient, DirectMessageReceived::class)->count();
        $this->assertGreaterThan(0, $told);

        $this->actingAs($sender)->put(route('forum.ignores.store', $recipient))->assertRedirect();

        $this->actingAs($sender)
            ->from(route('messages.show', $recipient))
            ->post(route('messages.store', $recipient), ['body' => 'Again'])
            ->assertSessionHasErrors('user');

        $this->actingAs($recipient)
            ->from(route('messages.show', $sender))
            ->post(route('messages.store', $sender), ['body' => 'Hello?'])
            ->assertSessionHasErrors('user');

        $this->assertSame(1, DirectMessage::query()->count());
        $this->assertCount($told, Notification::sent($recipient, DirectMessageReceived::class));
        Notification::assertNotSentTo($sender, DirectMessageReceived::class);

        $this->actingAs($sender)
            ->get(route('messages.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('messages.data.0.body', 'Before')
                ->where('blocked', 'ignoring'));

        $this->actingAs($recipient)
            ->get(route('messages.show', $sender))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('blocked', 'unavailable'));

        $this->actingAs($sender)->put(route('forum.ignores.store', $moderator))->assertSessionHasErrors('user');

        ForumIgnore::query()->create([
            'user_id' => $sender->id,
            'ignored_user_id' => $moderator->id,
        ]);

        $this->actingAs($sender)
            ->get(route('members.show', $moderator))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('message.can', true));

        $this->actingAs($sender)
            ->post(route('messages.store', $moderator), ['body' => 'Hello moderator'])
            ->assertRedirect(route('messages.show', $moderator));

        Notification::assertSentTo($moderator, DirectMessageReceived::class);

        $this->actingAs($moderator)->put(route('forum.ignores.store', $sender))->assertRedirect();

        $moderatorHeard = Notification::sent($moderator, DirectMessageReceived::class)->count();

        $this->actingAs($sender)
            ->from(route('messages.show', $moderator))
            ->post(route('messages.store', $moderator), ['body' => 'Again'])
            ->assertSessionHasErrors('user');

        $this->assertCount($moderatorHeard, Notification::sent($moderator, DirectMessageReceived::class));
    }

    public function test_you_cannot_message_yourself_or_someone_who_is_gone(): void
    {
        Notification::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $banned = User::factory()->create(['is_banned' => true]);

        $this->actingAs($sender)
            ->from(route('members.show', $sender))
            ->post(route('messages.store', $sender), ['body' => 'Hi me'])
            ->assertSessionHasErrors('user');

        $this->actingAs($sender)
            ->get(route('messages.show', $sender))
            ->assertRedirect(route('messages.index'));

        $this->actingAs($sender)->get(route('messages.show', $banned))->assertNotFound();
        $this->actingAs($sender)->post(route('messages.store', $banned), ['body' => 'Hi'])->assertNotFound();

        $this->actingAs($sender)
            ->post(route('messages.store', $recipient), ['body' => 'Stay'])
            ->assertRedirect();

        $recipient->forceFill(['is_banned' => true])->save();

        $this->actingAs($sender)
            ->get(route('messages.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('member.banned', true)
                ->where('messages.data.0.body', 'Stay')
                ->where('blocked', 'unavailable'));

        $this->actingAs($sender)
            ->from(route('messages.show', $recipient))
            ->post(route('messages.store', $recipient), ['body' => 'More'])
            ->assertSessionHasErrors('user');

        $this->assertSame(1, DirectMessage::query()->count());

        $recipient->delete();

        $this->assertSame(0, DirectConversation::query()->count());
        $this->assertSame(0, DirectMessage::query()->count());

        auth()->logout();

        $this->get(route('members.show', $sender))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('message', null));

        $this->get(route('messages.index'))->assertRedirect(route('login'));
        $this->get(route('messages.show', $sender))->assertRedirect(route('login'));
    }
}
