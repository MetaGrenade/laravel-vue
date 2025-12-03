<?php

namespace Tests\Feature\Support;

use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportTicketBroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => 'localhost',
                    'port' => 6001,
                    'scheme' => 'http',
                ],
            ],
        ]);

        Permission::findOrCreate('support.acp.view', 'web');
    }

    public function test_owner_can_join_ticket_channel(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::factory()->for($owner)->create();

        $response = $this->authorizeChannel($owner, $ticket);

        $response->assertOk();
        $this->assertChannelDataMatchesUser($response, $owner);
    }

    public function test_assigned_agent_can_join_ticket_channel(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $ticket = SupportTicket::factory()
            ->for($owner)
            ->create(['assigned_to' => $assignee->id]);

        $response = $this->authorizeChannel($assignee, $ticket);

        $response->assertOk();
        $this->assertChannelDataMatchesUser($response, $assignee);
    }

    public function test_team_member_can_join_ticket_channel(): void
    {
        $owner = User::factory()->create();
        $teamMember = User::factory()->create();
        $team = SupportTeam::create(['name' => 'Tier 2']);
        $team->members()->attach($teamMember);

        $ticket = SupportTicket::factory()
            ->for($owner)
            ->create(['support_team_id' => $team->id]);

        $response = $this->authorizeChannel($teamMember, $ticket);

        $response->assertOk();
        $this->assertChannelDataMatchesUser($response, $teamMember);
    }

    public function test_assignees_team_member_can_join_ticket_channel(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $supportingAgent = User::factory()->create();
        $sharedTeam = SupportTeam::create(['name' => 'Assignee Helpers']);

        $sharedTeam->members()->attach([$assignee->id, $supportingAgent->id]);

        $ticket = SupportTicket::factory()
            ->for($owner)
            ->create(['assigned_to' => $assignee->id]);

        $response = $this->authorizeChannel($supportingAgent, $ticket);

        $response->assertOk();
        $this->assertChannelDataMatchesUser($response, $supportingAgent);
    }

    public function test_support_viewer_can_join_ticket_channel(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('support.acp.view');

        $ticket = SupportTicket::factory()->for($owner)->create();

        $response = $this->authorizeChannel($viewer, $ticket);

        $response->assertOk();
        $this->assertChannelDataMatchesUser($response, $viewer);
    }

    public function test_random_user_is_rejected(): void
    {
        $owner = User::factory()->create();
        $randomUser = User::factory()->create();
        $ticket = SupportTicket::factory()->for($owner)->create();

        $this->authorizeChannel($randomUser, $ticket)->assertForbidden();
    }

    private function authorizeChannel(User $user, SupportTicket $ticket)
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "support.tickets.{$ticket->id}",
            'socket_id' => '1234.5678',
        ]);
    }

    private function assertChannelDataMatchesUser($response, User $user): void
    {
        $channelData = json_decode($response->json('channel_data'), true);

        $this->assertSame($user->id, $channelData['id']);
        $this->assertSame($user->nickname, $channelData['nickname']);
        $this->assertSame($user->avatar_url, $channelData['avatar_url']);
    }
}
