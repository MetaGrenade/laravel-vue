<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: a demo site for local development, never for production (docs/install.md, "Demo data").
     *
     * A real site is set up with `php artisan metaforger:install`, which makes its own administrator by name and adds no demo data.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $member = User::factory()->create([
            'nickname' => 'Test User',
            'email' => 'test@example.com',
            'avatar_url' => 'https://i.pravatar.cc/150?img=68',
            'profile_bio' => 'Curious tester keeping an eye on new features.',
        ]);

        $this->call(BaselineSeeder::class);

        // RolePermissionSeeder gives the role to user 1, which is this member on a database that was just migrated. Where the ids have
        // moved on (tables emptied by hand, or a test database whose counters kept running) it would give it to nobody or to somebody else.
        $member->assignRole('admin');

        $this->call([
            DemoSeeder::class,
            LegalPagesSeeder::class,
        ]);
    }
}
