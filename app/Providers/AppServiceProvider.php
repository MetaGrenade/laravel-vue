<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only set MySQL’s time zone when you’re actually using MySQL
        // When GitHub Actions run there is no real .env file, so Laravel falls back to its default database connection
        // Which is set in config/database.php :19 'default' => env('DB_CONNECTION', 'sqlite'),
        if (DB::getDriverName() === 'mysql') {
            //force DB timestamps to use 'UTC' timezone for more accurate dayjs conversion to local timezones
            DB::statement("SET time_zone = '+00:00'");
        }
    }
}
