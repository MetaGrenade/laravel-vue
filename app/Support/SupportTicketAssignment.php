<?php

namespace App\Support;

use App\Models\SupportAssignmentRule;
use App\Models\User;

class SupportTicketAssignment
{
    public function __construct(
        public readonly User $assignee,
        public readonly SupportAssignmentRule $rule,
        public readonly bool $changed,
        public readonly ?int $previousAssigneeId,
    ) {
    }
}
