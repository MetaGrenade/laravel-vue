<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Demo data for a development machine: subscription plans with placeholder Stripe prices, demo staff and a service account with the
 * password `password`, a demo shop, and a poll. `php artisan metaforge:install --demo` and `db:seed` run it. It needs the roles of
 * {@see BaselineSeeder}, and it is never for a production site.
 */
class DemoSeeder extends Seeder
{
    /**
     * The accounts the demo data makes, by email address, with their nicknames. Where one of these emails is taken, the seeder resets that
     * account (the password included) instead of making a new one, and nicknames are unique, so the installer refuses an administrator
     * who would collide with one. A test keeps this list the same as what the seeders make.
     *
     * @return array<string, string>
     */
    public static function accounts(): array
    {
        return [
            TokenLogDemoSeeder::ADMIN_EMAIL => TokenLogDemoSeeder::ADMIN_NICKNAME,
            TokenLogDemoSeeder::SERVICE_EMAIL => TokenLogDemoSeeder::SERVICE_NICKNAME,
        ];
    }

    public function run(): void
    {
        $this->call([
            SubscriptionPlanSeeder::class,
            TokenLogDemoSeeder::class,
            CommerceDemoSeeder::class,
            PollDemoSeeder::class,
        ]);
    }
}
