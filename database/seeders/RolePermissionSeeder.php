<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
            'polls',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission.'.acp.view']);
            Permission::firstOrCreate(['name' => $permission.'.acp.create']);
            Permission::firstOrCreate(['name' => $permission.'.acp.edit']);
            if ($permission === 'users') {
                Permission::firstOrCreate(['name' => 'users.acp.update']);
            }
            if ($permission === 'commerce') {
                // Sending money back cannot be undone, so it is granted separately from editing.
                Permission::firstOrCreate(['name' => 'commerce.acp.refund']);
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
                // A warning is a notice and a ban shuts somebody out, so they are granted separately (docs/moderation.md).
                Permission::firstOrCreate(['name' => $permission.'.acp.warn']);
                // Seeing the site as a customer sees it (read only, and written to the audit log): shows what the customer sees, so it
                // is granted on its own.
                Permission::firstOrCreate(['name' => $permission.'.acp.impersonate']);
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

        // The audit log is only looked at: nothing in it is created, edited or deleted from the admin area.
        Permission::firstOrCreate(['name' => 'audit.acp.view']);

        // Optionally assign all permissions to admin
        $adminRole->syncPermissions(Permission::all());

        $editorRole->givePermissionTo([
            'polls.acp.view',
            'polls.acp.create',
            'polls.acp.edit',
        ]);

        // A moderator can see members, warn them and ban them (but not staff: only an administrator can moderate staff).
        $moderatorRole->givePermissionTo([
            'polls.acp.view',
            'users.acp.view',
            'users.acp.warn',
            'users.acp.ban',
        ]);

        // Assign the admin role to the user with ID 1, if the user exists
        $user = User::find(1);
        if ($user && ! $user->hasRole($adminRole)) {
            $user->assignRole($adminRole);
        }
    }
}
