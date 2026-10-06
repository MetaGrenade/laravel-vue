<?php

namespace App\Policies;

use App\Models\SupportTicketMessageAttachment;
use App\Models\User;

class SupportTicketMessageAttachmentPolicy
{
    /**
     * The ticket's owner and support staff may download its attachments.
     */
    public function download(User $user, SupportTicketMessageAttachment $attachment): bool
    {
        $ticket = $attachment->message?->ticket;

        if ($ticket === null) {
            return false;
        }

        return $ticket->user_id === $user->id || $user->can('support.acp.view');
    }
}
