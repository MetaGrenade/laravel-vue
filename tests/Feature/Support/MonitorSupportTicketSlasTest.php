<?php

namespace Tests\Feature\Support;

use App\Jobs\MonitorSupportTicketSlas;
use App\Models\SupportAssignmentRule;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SupportTicketAutoAssigner;
use App\Support\SupportTicketAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitorSupportTicketSlasTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalates_priority_when_threshold_exceeded(): void
    {
        $ticket = SupportTicket::factory()->create([
            'priority' => 'low',
            'created_at' => Carbon::now()->subHours(72),
        ]);

        (new MonitorSupportTicketSlas())->handle(
            app(SupportTicketAutoAssigner::class),
            app(SupportTicketAuditor::class)
        );

        $this->assertEquals('medium', $ticket->fresh()->priority);
        $this->assertDatabaseHas('support_ticket_audits', [
            'support_ticket_id' => $ticket->id,
            'action' => 'priority_escalated',
        ]);
    }

    public function test_reassigns_to_next_available_agent(): void
    {
        $primary = User::factory()->create();
        $backup = User::factory()->create();

        SupportAssignmentRule::create([
            'priority' => 'medium',
            'assigned_to' => $primary->id,
            'position' => 0,
        ]);

        SupportAssignmentRule::create([
            'priority' => null,
            'assigned_to' => $backup->id,
            'position' => 1,
        ]);

        $ticket = SupportTicket::factory()->create([
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);
        $assigner->assign($ticket);

        $ticket->forceFill([
            'updated_at' => Carbon::now()->subHours(48),
        ])->saveQuietly();

        (new MonitorSupportTicketSlas())->handle($assigner, app(SupportTicketAuditor::class));

        $this->assertEquals($backup->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseHas('support_ticket_audits', [
            'support_ticket_id' => $ticket->id,
            'action' => 'sla_reassigned',
        ]);
    }

    public function test_respects_custom_thresholds_from_settings(): void
    {
        SystemSetting::set('support.sla', [
            'priority_escalations' => [
                'low' => [
                    'after' => '1 hour',
                    'to' => 'high',
                ],
            ],
            'reassign_after' => [
                'low' => '90 minutes',
            ],
        ]);

        $primary = User::factory()->create();
        $backup = User::factory()->create();

        SupportAssignmentRule::create([
            'priority' => 'low',
            'assigned_to' => $primary->id,
            'position' => 0,
        ]);

        SupportAssignmentRule::create([
            'priority' => null,
            'assigned_to' => $backup->id,
            'position' => 1,
        ]);

        $ticket = SupportTicket::factory()->create([
            'priority' => 'low',
            'status' => 'open',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $assigner = app(SupportTicketAutoAssigner::class);
        $assigner->assign($ticket);

        $ticket->forceFill([
            'updated_at' => Carbon::now()->subHours(2),
        ])->saveQuietly();

        (new MonitorSupportTicketSlas())->handle($assigner, app(SupportTicketAuditor::class));

        $fresh = $ticket->fresh();

        $this->assertSame('high', $fresh->priority);
        $this->assertSame($backup->id, $fresh->assigned_to);
    }
}
