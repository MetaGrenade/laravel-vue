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
