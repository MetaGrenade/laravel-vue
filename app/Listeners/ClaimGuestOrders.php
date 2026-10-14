<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Commerce\GuestCarryOver;
use Illuminate\Auth\Events\Verified;

/**
 * When someone proves they own an email address, the guest orders placed with it become theirs.
 */
class ClaimGuestOrders
{
    public function __construct(private readonly GuestCarryOver $carryOver) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->carryOver->claimOrders($event->user);
        }
    }
}
