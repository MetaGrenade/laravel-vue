<?php

namespace App\Policies;

use App\Models\SupportTicket;
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

        if (! $ticket instanceof SupportTicket) {
            return false;
        }

        return app(SupportTicketPolicy::class)->view($user, $ticket) || $user->can('support.acp.view');
    }
}
