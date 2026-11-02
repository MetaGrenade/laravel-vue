<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: a demo site for local development, never for production (README, "Seed Data").
     *
     * The demo member is made first, because RolePermissionSeeder gives the administrator role to user 1. A real site is set up with
     * `php artisan metaforge:install`, which makes its own administrator by name and adds no demo data.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'nickname' => 'Test User',
            'email' => 'test@example.com',
            'avatar_url' => 'https://i.pravatar.cc/150?img=68',
            'profile_bio' => 'Curious tester keeping an eye on new features.',
        ]);

        $this->call([
            BaselineSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
