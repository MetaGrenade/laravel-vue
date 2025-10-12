<?php

namespace App\Support\OAuth;

class OAuthUser
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $nickname,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $avatar,
        public readonly array $raw,
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresIn = null,
    ) {
    }
}
