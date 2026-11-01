<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * What the Playwright smoke suite starts from (docs/e2e-tests.md): the records the accessibility audit opens, a member, an administrator,
 * and a free download to buy. Nothing here is demo content for a person using the site.
 *
 * Both people sign in with the factory's password, `password`. The administrator is given the role by name: RolePermissionSeeder gives it
 * to the account with id 1, which a database that has held users before (MySQL and PostgreSQL keep counting after a rollback) does not have.
 */
class SmokeFlowSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SupportTicketCategorySeeder::class,
        ]);

        User::factory()->create([
            'nickname' => 'Site Admin',
            'email' => 'admin@example.com',
            'avatar_url' => null,
        ])->assignRole('admin');

        $this->call(AccessibilityFlowSeeder::class);

        // Nothing to pay, so an order for it needs no payment provider; no shipping, so no address.
        Product::factory()->digital()->priced('0.00')->create([
            'name' => 'Starter guide',
            'slug' => 'starter-guide',
            'description' => 'A free guide to getting started.',
        ]);
    }
}
