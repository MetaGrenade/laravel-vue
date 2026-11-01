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
        // Scoped, not a singleton: it holds the container, the session and the providers it built from them (docs/octane.md).
        $this->app->scoped(OAuthManager::class, function ($app) {
            return new OAuthManager(
                $app,
                $app->make('session.store'),
                $app->make('url'),
            );
        });
    }
}
