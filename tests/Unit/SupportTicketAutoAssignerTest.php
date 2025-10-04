<?php

namespace Tests\Unit;

use App\Models\SupportAssignmentRule;
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
}
