<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportSatisfactionStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);
    }

    private function createSupportAgent(): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');
        $user->givePermissionTo('support.acp.view');

        return $user;
    }

    public function test_support_index_includes_satisfaction_aggregates(): void
    {
        $agent = $this->createSupportAgent();
        $requester = User::factory()->create();

        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'High rating',
            'body' => 'Ticket resolved perfectly.',
            'status' => 'closed',
            'priority' => 'low',
            'customer_satisfaction_rating' => 5,
            'resolved_at' => '2024-01-15 10:00:00',
            'resolved_by' => $agent->id,
        ]);

        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Lower rating',
            'body' => 'Follow-up took a while.',
            'status' => 'closed',
            'priority' => 'medium',
            'customer_satisfaction_rating' => 3,
            'resolved_at' => '2024-01-20 12:00:00',
            'resolved_by' => $agent->id,
        ]);

        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Pending feedback',
            'body' => 'Awaiting additional info.',
            'status' => 'pending',
            'priority' => 'high',
            'customer_satisfaction_rating' => 4,
            'resolved_at' => '2024-02-10 09:00:00',
            'resolved_by' => $agent->id,
        ]);

        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Unrated open ticket',
            'body' => 'Still in progress.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($agent)->get(route('acp.support.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/Support')
            ->where('supportStats.satisfaction.average', 4)
            ->where('supportStats.satisfaction.count', 3)
            ->where('supportStats.satisfaction.by_status.pending.average', 4)
            ->where('supportStats.satisfaction.by_status.pending.count', 1)
            ->where('supportStats.satisfaction.by_status.closed.average', 4)
            ->where('supportStats.satisfaction.by_status.closed.count', 2)
            ->where('supportStats.satisfaction.by_status.open.average', null)
            ->where('supportStats.satisfaction.by_status.open.count', 0)
            ->where('supportStats.satisfaction.by_month', [
                ['month' => '2024-01', 'average' => 4, 'count' => 2],
                ['month' => '2024-02', 'average' => 4, 'count' => 1],
            ])
        );
    }
}
