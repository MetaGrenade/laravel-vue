<?php

namespace App\Support\Api;

use Illuminate\Validation\ValidationException;

/**
 * An API key that cannot be made, said in words the person can act on. It is a validation error on the `limit` field, so the form
 * shows it the way it does any other bad input.
 */
class ApiKeyRejected extends ValidationException
{
    public static function tooMany(int $max): static
    {
        return static::withMessages([
            'limit' => ['You already have '.$max.' API '.($max === 1 ? 'key' : 'keys').' you can use. Revoke one you no longer need, then make another.'],
        ]);
    }
}
