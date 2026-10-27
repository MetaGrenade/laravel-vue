<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

/**
 * A ticket belongs to the person who opened it. Staff reach it through the
 * admin permissions, not through this policy. An administrator still passes,
 * because an administrator is allowed every ability.
 */
class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    public function close(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    public function reopen(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    public function rate(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    private function owns(User $user, SupportTicket $ticket): bool
    {
        return (int) $ticket->user_id === (int) $user->id;
    }
}
