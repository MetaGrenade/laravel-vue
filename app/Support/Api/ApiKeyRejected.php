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
            'limit' => [trans_choice('forms.api_keys.too_many', $max, ['max' => $max])],
        ]);
    }

    /** The site asks for a verified email address, and this person has not verified theirs. */
    public static function unverified(): static
    {
        return static::withMessages([
            'email' => [__('forms.api_keys.unverified')],
        ]);
    }

    /** Rotating makes a new key while the old one goes on working, so there has to be room for both. */
    public static function noRoomToRotate(int $max): static
    {
        return static::withMessages([
            'limit' => [trans_choice('forms.api_keys.no_room_to_rotate', $max, ['max' => $max])],
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
        return static::withMessages(['limits' => [__('forms.api_keys.limits_from_staff')]]);
    }
}
