<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
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
            SupportTicketCategorySeeder::class,
            TokenLogDemoSeeder::class,
        ]);
    }
}
