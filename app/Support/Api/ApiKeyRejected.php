<?php

namespace App\Support\Api;

use Illuminate\Validation\ValidationException;

/**
 * An API key that cannot be made (or rotated, or limited), said in words the person can act on. It is a validation error (on the
 * `limit` field, or `email` for an address that is not verified), so a form or an API client is answered the way it is for any other
 * bad input.
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

    /** Rotating makes a new key while the old one goes on working, so there has to be room for both. */
    public static function noRoomToRotate(int $max): static
    {
        return static::withMessages([
            'limit' => ['You already have '.$max.' API '.($max === 1 ? 'key' : 'keys').' you can use, and rotating with a grace period needs room for one more while the old key winds down. Revoke one you no longer need, or rotate this key and stop the old one at once.'],
        ]);
    }

    /** It is revoked, has run out, is already being replaced, or holds nothing worth copying. */
    public static function cannotRotate(string $why): static
    {
        return static::withMessages(['key' => [$why]]);
    }

    /** The limits were set by staff, so they are not the owner's to change. */
    public static function limitsFromStaff(): static
    {
        return static::withMessages(['limits' => ['Staff set the limits on this key, so you cannot change them.']]);
    }
}
