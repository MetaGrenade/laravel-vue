<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.status', 'guard_name' => 'web']);
    }

    public function test_admin_can_bulk_update_ticket_statuses(): void
    {
        Carbon::setTestNow('2025-02-15 12:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo('support.acp.status');

        $tickets = SupportTicket::factory()
            ->count(2)
            ->state(['status' => 'open'])
            ->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.support.index'))
            ->patch(route('acp.support.tickets.bulk-status'), [
                'status' => 'closed',
                'ids' => $tickets->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Updated 2 support tickets.');

        foreach ($tickets as $ticket) {
            $fresh = $ticket->fresh();
            $this->assertSame('closed', $fresh->status);
            $this->assertSame($admin->id, $fresh->resolved_by);
            $this->assertNotNull($fresh->resolved_at);
            $this->assertTrue($fresh->resolved_at->equalTo(Carbon::now()));
        }

        Carbon::setTestNow();
    }

    public function test_user_without_permission_cannot_bulk_update_ticket_statuses(): void
    {
        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create();

        $response = $this->actingAs($user)
            ->patch(route('acp.support.tickets.bulk-status'), [
                'status' => 'closed',
                'ids' => [$ticket->id],
            ]);

        $response->assertForbidden();
    }
}
