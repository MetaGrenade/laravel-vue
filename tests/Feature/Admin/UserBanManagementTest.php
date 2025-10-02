<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserBanManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createBanPermission(): Permission
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::firstOrCreate(['name' => 'users.acp.ban']);
    }

    public function test_authorized_users_can_ban_and_unban_accounts(): void
    {
        $banPermission = $this->createBanPermission();

        $role = Role::firstOrCreate(['name' => 'admin']);
        $role->givePermissionTo($banPermission);

        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        $target = User::factory()->create();

        $this->put(route('acp.users.ban', $target))
            ->assertRedirect(route('acp.users.index'));

        $target->refresh();
        $this->assertTrue($target->is_banned);
        $this->assertNotNull($target->banned_at);
        $this->assertSame($admin->id, $target->banned_by_id);

        $this->put(route('acp.users.unban', $target))
            ->assertRedirect(route('acp.users.index'));

        $target->refresh();
        $this->assertFalse($target->is_banned);
        $this->assertNull($target->banned_at);
        $this->assertNull($target->banned_by_id);
    }

    public function test_users_without_permission_cannot_toggle_bans(): void
    {
        $this->createBanPermission();

        $editorRole = Role::firstOrCreate(['name' => 'editor']);

        $editor = User::factory()->create();
        $editor->assignRole($editorRole);
        $this->actingAs($editor);

        $target = User::factory()->create();

        $this->put(route('acp.users.ban', $target))
            ->assertForbidden();

        $this->assertFalse($target->fresh()->is_banned);
    }

    public function test_banned_users_are_flagged_in_admin_index(): void
    {
        $banPermission = $this->createBanPermission();

        $role = Role::firstOrCreate(['name' => 'admin']);
        $role->givePermissionTo($banPermission);

        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        User::factory()->create();
        $bannedUser = User::factory()->create();
        $bannedUser->forceFill([
            'is_banned' => true,
            'banned_at' => now(),
            'banned_by_id' => $admin->id,
        ])->save();

        $response = $this->get(route('acp.users.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('users.data', function ($users) use ($bannedUser) {
                return collect($users)->contains(function ($user) use ($bannedUser) {
                    return $user['id'] === $bannedUser->id
                        && $user['is_banned'] === true;
                });
            })
        );
    }
}
