<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;
use App\Support\Ownership;

/**
 * Saved addresses belong to their owner (a user in 1.0, a team in 1.1); access
 * goes through {@see Ownership}, never a user id.
 */
class AddressPolicy
{
    public function view(User $user, Address $address): bool
    {
        return $address->isOwnedByAnyOwnerOf($user);
    }

    public function update(User $user, Address $address): bool
    {
        return $this->view($user, $address);
    }

    public function delete(User $user, Address $address): bool
    {
        return $this->view($user, $address);
    }
}
