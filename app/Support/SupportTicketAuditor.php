<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketAudit;
use App\Models\User;

class SupportTicketAuditor
{
    public function log(SupportTicket $ticket, string $action, array $context = [], ?User $performedBy = null): SupportTicketAudit
    {
        return $ticket->audits()->create([
            'action' => $action,
            'context' => $context !== [] ? $context : null,
            'performed_by' => $performedBy?->id,
        ]);
    }
}
