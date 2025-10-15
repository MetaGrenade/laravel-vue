<?php

namespace App\Support;

use App\Models\SupportAssignmentRule;
use App\Models\SupportTeam;
use App\Models\User;

class SupportTicketAssignment
{
    public function __construct(
        public readonly string $assigneeType,
        public readonly ?User $assignee,
        public readonly ?SupportTeam $team,
        public readonly SupportAssignmentRule $rule,
        public readonly bool $changed,
        public readonly ?int $previousAssigneeId,
        public readonly ?int $previousTeamId,
    ) {
    }
}
