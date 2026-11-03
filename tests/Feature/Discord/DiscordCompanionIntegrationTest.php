<?php

namespace Tests\Feature\Discord;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketAgentReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DiscordCompanionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Permission::create(['name' => 'system.acp.view', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_acp_discord_pages_require_system_permission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('admin');
        $user->givePermissionTo('system.acp.view');

        $this->actingAs($user)
            ->get(route('acp.discord.role-mappings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('acp/DiscordRoleMappings'));

        $this->actingAs($user)
            ->get(route('acp.discord.sync'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('acp/DiscordSync'));
    }

    public function test_admin_sync_failures_api_is_protected_when_registered(): void
    {
        if (! $this->companionSyncFailuresRoutesRegistered()) {
            $this->markTestSkipped('Companion sync failures admin routes are not registered yet.');
        }

        $this->getJson('/discord/admin/sync/failures')->assertUnauthorized();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('admin');
        $user->givePermissionTo('system.acp.view');

        $this->actingAs($user)
            ->getJson('/discord/admin/sync/failures?unresolved=1')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_admin_sync_failures_unresolved_filter_when_registered(): void
    {
        if (! $this->companionSyncFailuresRoutesRegistered()) {
            $this->markTestSkipped('Companion sync failures admin routes are not registered yet.');
        }

        if (! class_exists(\MetaForge\Discord\Models\DiscordSyncFailure::class)) {
            $this->markTestSkipped('Companion package is not installed.');
        }

        $failureModel = \MetaForge\Discord\Models\DiscordSyncFailure::class;

        $failureModel::record(
            $failureModel::OPERATION_OUTBOUND_ROLE_SYNC,
            'Open failure',
            1,
        );
        $failureModel::record(
            $failureModel::OPERATION_OUTBOUND_ROLE_SYNC,
            'Closed failure',
            2,
        )->markResolved();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('admin');
        $user->givePermissionTo('system.acp.view');

        $this->actingAs($user)
            ->getJson('/discord/admin/sync/failures')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($user)
            ->getJson('/discord/admin/sync/failures?unresolved=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.error', 'Open failure');
    }

    public function test_admin_role_mapping_api_is_protected(): void
    {
        $this->getJson('/discord/admin/role-mappings')->assertUnauthorized();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('admin');
        $user->givePermissionTo('system.acp.view');

        $this->actingAs($user)
            ->postJson('/discord/admin/role-mappings', [
                'spatie_role_name' => 'member',
                'discord_role_id' => '123456789012345678',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('discord_role_mappings', [
            'spatie_role_name' => 'member',
            'discord_role_id' => '123456789012345678',
        ]);
    }

    public function test_user_can_update_discord_notification_preferences(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/discord/user/notification-preferences')
            ->assertOk()
            ->assertJsonStructure(['categories', 'preferences']);

        $this->actingAs($user)
            ->putJson('/discord/user/notification-preferences', [
                'preferences' => [
                    'support_ticket_reply' => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preferences.support_ticket_reply', true);
    }

    public function test_support_ticket_agent_reply_implements_discord_contract(): void
    {
        $ticket = new SupportTicket(['id' => 1, 'subject' => 'Help']);
        $message = new SupportTicketMessage([
            'id' => 1,
            'body' => 'We are looking into this.',
        ]);

        $notification = new SupportTicketAgentReply($ticket, $message);

        $this->assertSame('support_ticket_reply', $notification->discordNotificationCategory());
        $this->assertNotEmpty($notification->via(User::factory()->make()));
    }

    public function test_discord_settings_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('settings.discord-notifications.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/DiscordNotifications'));
    }

    private function companionSyncFailuresRoutesRegistered(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'discord/admin/sync/failures')) {
                return true;
            }
        }

        return false;
    }
}
