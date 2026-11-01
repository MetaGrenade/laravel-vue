<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * What the Playwright smoke suite starts from (docs/e2e-tests.md): the records the accessibility audit opens, a member, an administrator,
 * and a free download to buy. Nothing here is demo content for a person using the site.
 *
 * Both people sign in with the factory's password, `password`. The administrator is created first because RolePermissionSeeder gives the
 * administrator role to the account with id 1.
 */
class SmokeFlowSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'nickname' => 'Site Admin',
            'email' => 'admin@example.com',
            'avatar_url' => null,
        ]);

        $this->call([
            RolePermissionSeeder::class,
            SupportTicketCategorySeeder::class,
            AccessibilityFlowSeeder::class,
        ]);

        // Nothing to pay, so an order for it needs no payment provider; no shipping, so no address.
        Product::factory()->digital()->priced('0.00')->create([
            'name' => 'Starter guide',
            'slug' => 'starter-guide',
            'description' => 'A free guide to getting started.',
        ]);
    }
}
