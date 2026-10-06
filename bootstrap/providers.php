<?php

use App\Providers\AppServiceProvider;
use App\Providers\OAuthServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

return [
    AppServiceProvider::class,
    OAuthServiceProvider::class,
    PermissionServiceProvider::class,
];
