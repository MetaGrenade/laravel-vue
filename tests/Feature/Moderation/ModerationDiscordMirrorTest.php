<?php

namespace Tests\Feature\Moderation;

use App\Events\ModerationDiscordMirror;
use App\Models\ModerationAction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ModerationDiscordMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    public function test_warn_from_acp_dispatches_moderation_discord_mirror_once(): void
    {
        Event::fake([ModerationDiscordMirror::class]);

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        $member = User::factory()->create();

        $this->actingAs($moderator)
            ->post(route('acp.users.warn', $member), ['reason' => 'Please keep to the topic'])
            ->assertRedirect();

        $action = ModerationAction::query()->sole();

        Event::assertDispatchedTimes(ModerationDiscordMirror::class, 1);
        Event::assertDispatched(
            ModerationDiscordMirror::class,
            fn (ModerationDiscordMirror $event) => $event->moderationAction()->is($action)
                && $event->moderationAction()->type === ModerationAction::WARNING
                && $event->moderationActionUrl() === route('acp.users.moderation.show', $member),
        );
    }

    public function test_ban_from_acp_dispatches_moderation_discord_mirror_once(): void
    {
        Event::fake([ModerationDiscordMirror::class]);

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        $member = User::factory()->create();

        $this->actingAs($moderator)
            ->put(route('acp.users.ban', $member), ['reason' => 'Repeated spam'])
            ->assertRedirect();

        $action = ModerationAction::query()->sole();

        Event::assertDispatchedTimes(ModerationDiscordMirror::class, 1);
        Event::assertDispatched(
            ModerationDiscordMirror::class,
            fn (ModerationDiscordMirror $event) => $event->moderationAction()->is($action)
                && $event->moderationAction()->type === ModerationAction::BAN,
        );
    }
}
