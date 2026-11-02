<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What every site needs before anyone uses it, and nothing that belongs to a demo: the roles and their permissions, the support
 * categories, the reputation badges. `php artisan metaforge:install` runs it (docs/install.md), and so does `db:seed`.
 *
 * It is for a site that has none of this yet. The seeders in it put back what staff may have removed on purpose (a support category, a
 * badge, a permission taken from a role), so it is not run again on a site that is set up.
 */
class BaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SupportTicketCategorySeeder::class,
            BadgeSeeder::class,
        ]);
    }
}
