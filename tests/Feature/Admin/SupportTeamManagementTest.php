<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);

        collect([
            'support_teams.acp.view',
            'support_teams.acp.edit',
        ])->each(fn (string $permission) => Permission::create([
            'name' => $permission,
            'guard_name' => 'web',
        ]));
    }

    public function test_admin_can_update_team_memberships(): void
    {
        $admin = $this->createAdminWithPermissions([
            'support_teams.acp.view',
            'support_teams.acp.edit',
        ]);

        $teamA = SupportTeam::create(['name' => 'Onboarding']);
        $teamB = SupportTeam::create(['name' => 'Escalations']);
        $agent = User::factory()->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.support.teams.index'))
            ->put(route('acp.support.teams.memberships.update', $agent), [
                'team_ids' => [$teamA->id, $teamB->id],
            ]);

        $response->assertRedirect(route('acp.support.teams.index'));
        $response->assertSessionHas('success', 'Team membership updated.');

        $this->assertDatabaseHas('support_team_user', [
            'support_team_id' => $teamA->id,
            'user_id' => $agent->id,
        ]);

        $this->assertDatabaseHas('support_team_user', [
            'support_team_id' => $teamB->id,
            'user_id' => $agent->id,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('acp.support.teams.index'))
            ->put(route('acp.support.teams.memberships.update', $agent), [
                'team_ids' => [],
            ]);

        $response->assertRedirect(route('acp.support.teams.index'));
        $response->assertSessionHas('success', 'Team membership updated.');

        $this->assertDatabaseMissing('support_team_user', [
            'support_team_id' => $teamA->id,
            'user_id' => $agent->id,
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
