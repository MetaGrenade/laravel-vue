<?php

namespace Tests\Unit;

use App\Models\SupportAssignmentRule;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\SupportTicketAutoAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketAutoAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_matching_rule(): void
    {
        $agent = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'priority' => 'high',
            'assigned_to' => null,
            'support_team_id' => null,
        ]);

        SupportAssignmentRule::create([
            'priority' => 'high',
            'assigned_to' => $agent->id,
            'position' => 0,
            'active' => true,
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);

        $result = $assigner->assign($ticket);

        $this->assertNotNull($result);
        $this->assertTrue($result->changed);
        $this->assertEquals($agent->id, $ticket->fresh()->assigned_to);
        $this->assertNull($ticket->fresh()->support_team_id);
        $this->assertDatabaseHas('support_ticket_audits', [
            'support_ticket_id' => $ticket->id,
            'action' => 'auto_assigned',
        ]);
    }

    public function test_reassignment_skips_excluded_agents(): void
    {
        $primaryAgent = User::factory()->create();
        $secondaryAgent = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'priority' => 'medium',
            'assigned_to' => null,
        ]);

        SupportAssignmentRule::create([
            'priority' => 'medium',
            'assigned_to' => $primaryAgent->id,
            'position' => 0,
            'active' => true,
        ]);

        SupportAssignmentRule::create([
            'priority' => null,
            'assigned_to' => $secondaryAgent->id,
            'position' => 1,
            'active' => true,
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);

        $assigner->assign($ticket);
        $ticket->refresh();

        $result = $assigner->assign($ticket, [
            'exclude' => [$primaryAgent->id],
            'reason' => 'sla_reassigned',
            'meta' => ['threshold' => 'example'],
        ]);

        $this->assertNotNull($result);
        $this->assertTrue($result->changed);
        $this->assertEquals($secondaryAgent->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('support_ticket_audits', [
            'support_ticket_id' => $ticket->id,
            'action' => 'sla_reassigned',
        ]);
    }

    public function test_assigns_team_rule_sets_support_team(): void
    {
        $team = SupportTeam::create(['name' => 'Escalations']);
        $ticket = SupportTicket::factory()->create([
            'priority' => 'medium',
            'assigned_to' => null,
            'support_team_id' => null,
        ]);

        SupportAssignmentRule::create([
            'priority' => 'medium',
            'assignee_type' => 'team',
            'support_team_id' => $team->id,
            'position' => 0,
            'active' => true,
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);

        $result = $assigner->assign($ticket);

        $this->assertNotNull($result);
        $this->assertTrue($result->changed);
        $this->assertSame('team', $result->assigneeType);
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertEquals($team->id, $ticket->fresh()->support_team_id);
        $this->assertEquals($team->id, $result->team?->id);
    }

    public function test_assigning_user_after_team_clears_support_team(): void
    {
        $team = SupportTeam::create(['name' => 'Developers']);
        $agent = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'priority' => 'low',
            'assigned_to' => null,
            'support_team_id' => $team->id,
        ]);

        SupportAssignmentRule::create([
            'priority' => 'low',
            'assignee_type' => 'user',
            'assigned_to' => $agent->id,
            'position' => 0,
            'active' => true,
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);

        $result = $assigner->assign($ticket);

        $this->assertNotNull($result);
        $this->assertTrue($result->changed);
        $this->assertSame('user', $result->assigneeType);
        $this->assertEquals($agent->id, $ticket->fresh()->assigned_to);
        $this->assertNull($ticket->fresh()->support_team_id);
    }
}
