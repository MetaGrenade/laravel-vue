<?php

namespace App\Support\OAuth;

/**
 * Who a provider says the person is. It carries no token: a provider class uses the access token it was given to ask who the person is,
 * and the token goes no further. Nothing after the sign-in needs one, and a token that is passed around can be saved, logged or dumped.
 */
class OAuthUser
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $nickname,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $avatar,
        /** What the provider sent about the person, as it sent it. Used while signing in; the site does not store it. */
        public readonly array $raw,
        /** Whether the provider says the person owns {@see self::$email}. A provider that does not say has not verified it. */
        public readonly bool $emailVerified = false,
    ) {}
}
