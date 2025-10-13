<?php

namespace App\Providers;

use App\Models\Blog;
use App\Models\ForumPost;
use App\Models\PersonalAccessToken;
use App\Policies\BlogPolicy;
use App\Policies\ForumPostPolicy;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SubscriptionManager::class, fn () => new SubscriptionManager());
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

        Gate::policy(ForumPost::class, ForumPostPolicy::class);
        Gate::policy(Blog::class, BlogPolicy::class);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
