<?php

namespace App\Support\Api;

use Illuminate\Validation\ValidationException;

/**
 * An API key that cannot be made, said in words the person can act on. It is a validation error (on the `limit` field, or `email`
 * for an address that is not verified), so a form or an API client is answered the way it is for any other bad input.
 */
class ApiKeyRejected extends ValidationException
{
    public static function tooMany(int $max): static
    {
        return static::withMessages([
            'limit' => ['You already have '.$max.' API '.($max === 1 ? 'key' : 'keys').' you can use. Revoke one you no longer need, then make another.'],
        ]);
    }

    /** The site asks for a verified email address, and this person has not verified theirs. */
    public static function unverified(): static
    {
        return static::withMessages([
            'email' => ['Verify your email address first: API keys can only be made once it is verified.'],
        ]);
    }
}
