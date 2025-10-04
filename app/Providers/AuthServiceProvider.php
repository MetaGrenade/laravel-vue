<?php

namespace App\Providers;

use App\Models\BlogRevision;
use App\Policies\BlogRevisionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        BlogRevision::class => BlogRevisionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
