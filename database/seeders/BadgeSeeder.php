<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'name' => 'First Reply',
                'slug' => 'first-reply',
                'description' => 'Awarded for sharing your first forum reply.',
                'points_required' => 5,
            ],
            [
                'name' => 'Helpful Contributor',
                'slug' => 'helpful-contributor',
                'description' => 'Earned after collecting 100 reputation points.',
                'points_required' => 100,
            ],
            [
                'name' => 'Trusted Sage',
                'slug' => 'trusted-sage',
                'description' => 'Granted to experts with 500 reputation or more.',
                'points_required' => 500,
            ],
        ];

        foreach ($defaults as $badge) {
            Badge::firstOrCreate(
                ['slug' => $badge['slug']],
                $badge + ['is_active' => true]
            );
        }
    }
}
