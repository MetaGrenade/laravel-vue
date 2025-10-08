<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.verify', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.ban', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.delete', 'guard_name' => 'web']);
    }

    protected function createAdminWithPermission(string $permission): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo($permission);

        return $admin;
    }

    public function test_admin_can_bulk_verify_users(): void
    {
        Carbon::setTestNow('2025-03-01 15:30:00');

        $admin = $this->createAdminWithPermission('users.acp.verify');

        $users = User::factory()
            ->count(3)
            ->state(['email_verified_at' => null])
            ->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'verify',
                'ids' => $users->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.users.index'));
        $response->assertSessionHas('success', 'Verified 3 users.');

        foreach ($users as $user) {
            $fresh = $user->fresh();
            $this->assertNotNull($fresh->email_verified_at);
            $this->assertTrue($fresh->email_verified_at->equalTo(Carbon::now()));
        }

        Carbon::setTestNow();
    }

    public function test_admin_can_bulk_ban_users(): void
    {
        Carbon::setTestNow('2025-03-02 09:00:00');

        $admin = $this->createAdminWithPermission('users.acp.ban');

        $users = User::factory()->count(2)->state([
            'is_banned' => false,
            'banned_at' => null,
            'banned_by_id' => null,
        ])->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'ban',
                'ids' => $users->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.users.index'));
        $response->assertSessionHas('success', 'Banned 2 users.');

        foreach ($users as $user) {
            $fresh = $user->fresh();
            $this->assertTrue($fresh->is_banned);
            $this->assertTrue($fresh->banned_at->equalTo(Carbon::now()));
            $this->assertSame($admin->id, $fresh->banned_by_id);
        }

        Carbon::setTestNow();
    }

    public function test_admin_can_bulk_unban_users(): void
    {
        $admin = $this->createAdminWithPermission('users.acp.ban');

        $users = User::factory()->count(2)->create();

        foreach ($users as $user) {
            $user->forceFill([
                'is_banned' => true,
                'banned_at' => now(),
                'banned_by_id' => $admin->id,
            ])->save();
        }

        $response = $this->actingAs($admin)
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'unban',
                'ids' => $users->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.users.index'));
        $response->assertSessionHas('success', 'Unbanned 2 users.');

        foreach ($users as $user) {
            $fresh = $user->fresh();
            $this->assertFalse($fresh->is_banned);
            $this->assertNull($fresh->banned_at);
            $this->assertNull($fresh->banned_by_id);
        }
    }

    public function test_admin_can_bulk_delete_users(): void
    {
        $admin = $this->createAdminWithPermission('users.acp.delete');

        $users = User::factory()->count(2)->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.users.index'))
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'delete',
                'ids' => $users->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.users.index'));
        $response->assertSessionHas('success', 'Deleted 2 users.');

        foreach ($users as $user) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
    }

    public function test_bulk_actions_require_corresponding_permission(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->state(['email_verified_at' => null])->create();

        $response = $this->actingAs($user)
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'verify',
                'ids' => [$target->id],
            ]);

        $response->assertForbidden();
    }
}
