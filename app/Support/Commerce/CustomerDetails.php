<?php

namespace App\Support\Commerce;

use App\Models\User;

/**
 * Who is checking out. $user is null for a guest.
 */
final readonly class CustomerDetails
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?User $user = null,
    ) {}
}
