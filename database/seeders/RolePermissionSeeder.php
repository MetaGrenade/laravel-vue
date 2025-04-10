<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        // Create roles
        $adminRole = Role::create(['name' => 'admin']);
        $editorRole = Role::create(['name' => 'editor']);
        $moderatorRole = Role::create(['name' => 'moderator']);
        $userRole = Role::create(['name' => 'user']);

        // Create permissions (adjust as needed)
        $permissions = [
            'dashboard',
            'users',
            'permissions',
            'blogs',
            'forums',
            'support',
            'system'
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission.'.view']);
            Permission::create(['name' => $permission.'.create']);
            Permission::create(['name' => $permission.'.acp.manage']);
            Permission::create(['name' => $permission.'.acp.edit']);
            if ($permission == 'blogs') {
                Permission::create(['name' => $permission.'.acp.publish']);
            }
            if ($permission == 'forums') {
                Permission::create(['name' => $permission.'.acp.move']);
                Permission::create(['name' => $permission.'.acp.publish']);
                Permission::create(['name' => $permission.'.acp.lock']);
                Permission::create(['name' => $permission.'.acp.pin']);
                Permission::create(['name' => $permission.'.acp.migrate']);
                Permission::create(['name' => $permission.'.acp.permissions']);
            }
            if ($permission == 'users') {
                Permission::create(['name' => $permission.'.acp.verify']);
            }
            if ($permission == 'support') {
                Permission::create(['name' => $permission.'.acp.assign']);
                Permission::create(['name' => $permission.'.acp.priority']);
                Permission::create(['name' => $permission.'.acp.status']);
            }
            Permission::create(['name' => $permission.'.acp.delete']);
        }

        // Optionally assign all permissions to admin
        $adminRole->syncPermissions(Permission::all());

        // Assign the admin role to the user with ID 1, if the user exists
        $user = User::find(1);
        if ($user) {
            $user->assignRole($adminRole);
        }
    }
}
