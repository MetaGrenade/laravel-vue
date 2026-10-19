<?php

namespace App\Support\Commerce;

use App\Models\User;

/**
 * Who is checking out. $user is null for a guest. $ip is the address the request came from, for providers that need to
 * know where the buyer is (Tebex works out tax from it).
 */
final readonly class CustomerDetails
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?User $user = null,
        public ?string $ip = null,
    ) {}
}
