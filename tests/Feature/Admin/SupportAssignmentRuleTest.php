<?php

namespace Tests\Feature\Admin;

use App\Models\SupportAssignmentRule;
use App\Models\SupportTeam;
use App\Models\SupportTicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportAssignmentRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAssignmentRule::flushCache();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);

        collect([
            'support_assignment_rules.acp.view',
            'support_assignment_rules.acp.create',
            'support_assignment_rules.acp.edit',
            'support_assignment_rules.acp.delete',
        ])->each(fn (string $permission) => Permission::create([
            'name' => $permission,
            'guard_name' => 'web',
        ]));
    }

    public function test_admin_can_view_assignment_rules(): void
    {
        $admin = $this->createAdminWithPermissions([
            'support_assignment_rules.acp.view',
        ]);

        $category = SupportTicketCategory::factory()->create(['name' => 'Billing']);
        $agent = User::factory()->create(['nickname' => 'Agent Smith']);

        SupportAssignmentRule::create([
            'support_ticket_category_id' => $category->id,
            'priority' => 'high',
            'assignee_type' => 'user',
            'assigned_to' => $agent->id,
            'position' => 1,
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('acp.support.assignment-rules.index'));

        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/SupportAssignmentRules')
            ->where('rules.0.assignee.nickname', 'Agent Smith')
            ->where('rules.0.assignee_type', 'user')
            ->where('categories.0.name', 'Billing')
            ->where('can.create', false)
        );
    }

    public function test_admin_can_create_assignment_rule(): void
    {
        $admin = $this->createAdminWithPermissions([
            'support_assignment_rules.acp.view',
            'support_assignment_rules.acp.create',
        ]);

        $category = SupportTicketCategory::factory()->create();
        $agent = User::factory()->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.support.assignment-rules.index'))
            ->post(route('acp.support.assignment-rules.store'), [
                'support_ticket_category_id' => $category->id,
                'priority' => 'medium',
                'assignee_type' => 'user',
                'assigned_to' => $agent->id,
                'active' => true,
            ]);

        $response->assertRedirect(route('acp.support.assignment-rules.index'));
        $response->assertSessionHas('success', 'Assignment rule created.');

        $this->assertDatabaseHas('support_assignment_rules', [
            'support_ticket_category_id' => $category->id,
            'priority' => 'medium',
            'assignee_type' => 'user',
            'assigned_to' => $agent->id,
            'active' => true,
        ]);
    }

    public function test_admin_can_reorder_assignment_rules(): void
    {
        $admin = $this->createAdminWithPermissions([
            'support_assignment_rules.acp.view',
            'support_assignment_rules.acp.edit',
        ]);

        $agentOne = User::factory()->create();
        $agentTwo = User::factory()->create();

        $first = SupportAssignmentRule::create([
            'support_ticket_category_id' => null,
            'priority' => null,
            'assignee_type' => 'user',
            'assigned_to' => $agentOne->id,
            'position' => 1,
            'active' => true,
        ]);

        $second = SupportAssignmentRule::create([
            'support_ticket_category_id' => null,
            'priority' => null,
            'assignee_type' => 'user',
            'assigned_to' => $agentTwo->id,
            'position' => 2,
            'active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('acp.support.assignment-rules.index'))
            ->patch(route('acp.support.assignment-rules.reorder', $second), [
                'direction' => 'up',
            ]);

        $response->assertRedirect(route('acp.support.assignment-rules.index'));
        $response->assertSessionHas('success', 'Assignment rule order updated.');

        $this->assertSame(2, $first->fresh()->position);
        $this->assertSame(1, $second->fresh()->position);
    }

    public function test_admin_can_assign_rule_to_team(): void
    {
        $admin = $this->createAdminWithPermissions([
            'support_assignment_rules.acp.view',
            'support_assignment_rules.acp.create',
        ]);

        $team = SupportTeam::create(['name' => 'Escalations']);

        $response = $this->actingAs($admin)
            ->from(route('acp.support.assignment-rules.index'))
            ->post(route('acp.support.assignment-rules.store'), [
                'support_ticket_category_id' => null,
                'priority' => null,
                'assignee_type' => 'team',
                'support_team_id' => $team->id,
                'active' => true,
            ]);

        $response->assertRedirect(route('acp.support.assignment-rules.index'));
        $response->assertSessionHas('success', 'Assignment rule created.');

        $this->assertDatabaseHas('support_assignment_rules', [
            'assignee_type' => 'team',
            'support_team_id' => $team->id,
        ]);
    }

    private function createAdminWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
