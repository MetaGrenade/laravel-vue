<?php

namespace App\Providers;

use App\Support\OAuth\OAuthManager;
use Illuminate\Support\ServiceProvider;

class OAuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(OAuthManager::class, function ($app) {
            return new OAuthManager(
                $app,
                $app->make('session.store'),
                $app->make('url'),
            );
        });
    }
}
