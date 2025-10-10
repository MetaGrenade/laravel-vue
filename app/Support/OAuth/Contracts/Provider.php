<?php

namespace App\Support\OAuth\Contracts;

use App\Support\OAuth\OAuthUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface Provider
{
    /**
     * Redirect the user to the provider's authentication page.
     */
    public function redirect(): RedirectResponse;

    /**
     * Retrieve the user details from the provider.
     */
    public function user(Request $request): OAuthUser;
}
