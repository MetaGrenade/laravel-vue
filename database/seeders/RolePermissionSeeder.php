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
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $editorRole = Role::firstOrCreate(['name' => 'editor']);
        $moderatorRole = Role::firstOrCreate(['name' => 'moderator']);
        $userRole = Role::firstOrCreate(['name' => 'user']);

        // Create permissions (adjust as needed)
        $permissions = [
            'users',
            'acl',
            'blogs',
            'forums',
            'support',
            'support_teams',
            'support_assignment_rules',
            'support_templates',
            'reputation',
            'tokens',
            'system',
            'billing',
            'trust_safety',
            'search',
            'commerce',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission.'.acp.view']);
            Permission::firstOrCreate(['name' => $permission.'.acp.create']);
            Permission::firstOrCreate(['name' => $permission.'.acp.edit']);
            if ($permission === 'users') {
                Permission::firstOrCreate(['name' => 'users.acp.update']);
            }
            if ($permission == 'blogs') {
                Permission::firstOrCreate(['name' => $permission.'.acp.publish']);
            }
            if ($permission == 'forums') {
                Permission::firstOrCreate(['name' => $permission.'.acp.move']);
                Permission::firstOrCreate(['name' => $permission.'.acp.publish']);
                Permission::firstOrCreate(['name' => $permission.'.acp.lock']);
                Permission::firstOrCreate(['name' => $permission.'.acp.pin']);
                Permission::firstOrCreate(['name' => $permission.'.acp.migrate']);
                Permission::firstOrCreate(['name' => $permission.'.acp.permissions']);
            }
            if ($permission == 'users') {
                Permission::firstOrCreate(['name' => $permission.'.acp.verify']);
                Permission::firstOrCreate(['name' => $permission.'.acp.ban']);
            }
            if ($permission == 'support') {
                Permission::firstOrCreate(['name' => $permission.'.acp.assign']);
                Permission::firstOrCreate(['name' => $permission.'.acp.move']);
                Permission::firstOrCreate(['name' => $permission.'.acp.publish']);
                Permission::firstOrCreate(['name' => $permission.'.acp.priority']);
                Permission::firstOrCreate(['name' => $permission.'.acp.reply']);
                Permission::firstOrCreate(['name' => $permission.'.acp.status']);
            }
            Permission::firstOrCreate(['name' => $permission.'.acp.delete']);
        }

        // Optionally assign all permissions to admin
        $adminRole->syncPermissions(Permission::all());

        // Assign the admin role to the user with ID 1, if the user exists
        $user = User::find(1);
        if ($user && !$user->hasRole($adminRole)) {
            $user->assignRole($adminRole);
        }
    }
}
