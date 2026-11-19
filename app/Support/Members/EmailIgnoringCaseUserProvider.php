<?php

namespace App\Support\Members;

use Illuminate\Auth\EloquentUserProvider;

/**
 * The `eloquent` user provider, which finds an account by the address as it is stored, taught that `Ada@Example.com` and
 * `ada@example.com` are one address ({@see EmailLookup}).
 *
 * Every lookup of an account by its credentials goes through here: `Auth::attempt()` (the sign-in page) and the password broker (asking for
 * a reset link, and resetting), so none of them has to remember. The address is turned into the one the account holds and the rest is
 * the parent's, so any other credential given with it still has to match.
 */
final class EmailIgnoringCaseUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $email = $credentials['email'] ?? null;

        if (is_string($email)) {
            $credentials['email'] = EmailLookup::storedAs($email) ?? $email;
        }

        return parent::retrieveByCredentials($credentials);
    }
}
