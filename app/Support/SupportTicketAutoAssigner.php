<?php

namespace App\Support;

use App\Models\SupportAssignmentRule;
use App\Models\SupportTicket;
use Illuminate\Support\Collection;

class SupportTicketAutoAssigner
{
    public function __construct(
        private readonly SupportTicketAuditor $auditor
    ) {
    }

    public function assign(SupportTicket $ticket, array $options = []): ?SupportTicketAssignment
    {
        $exclude = array_map('intval', $options['exclude'] ?? []);
        $reason = $options['reason'] ?? 'auto_assigned';
        $meta = $options['meta'] ?? [];

        $rules = $this->rules();

        foreach ($rules as $rule) {
            if (! $rule->active) {
                continue;
            }

            if ($rule->assignee_type === 'user' && ! $rule->assignee) {
                continue;
            }

            if ($rule->assignee_type === 'team' && ! $rule->team) {
                continue;
            }

            if ($rule->support_ticket_category_id && (int) $ticket->support_ticket_category_id !== (int) $rule->support_ticket_category_id) {
                continue;
            }

            if ($rule->priority && $rule->priority !== $ticket->priority) {
                continue;
            }

            if ($rule->assignee_type === 'user' && in_array((int) $rule->assigned_to, $exclude, true)) {
                continue;
            }

            $previousAssignee = $ticket->assigned_to ? (int) $ticket->assigned_to : null;

            $previousTeam = $ticket->support_team_id ? (int) $ticket->support_team_id : null;

            if ($rule->assignee_type === 'team') {
                $ticket->forceFill([
                    'assigned_to' => null,
                    'support_team_id' => $rule->support_team_id,
                ]);
            } else {
                $ticket->forceFill([
                    'assigned_to' => $rule->assigned_to,
                    'support_team_id' => null,
                ]);
            }

            $changed = $ticket->isDirty(['assigned_to', 'support_team_id']);

            if ($changed) {
                $ticket->save();
            }

            if ($rule->assignee_type === 'team') {
                $ticket->unsetRelation('assignee');
                $ticket->setRelation('team', $rule->team);
            } else {
                $ticket->setRelation('assignee', $rule->assignee);
                $ticket->unsetRelation('team');
            }

            if ($changed) {
                $context = array_merge($meta, [
                    'rule_id' => $rule->id,
                    'assignee_type' => $rule->assignee_type,
                    'assigned_to' => $rule->assignee_type === 'user' ? (int) $rule->assigned_to : null,
                    'support_team_id' => $rule->assignee_type === 'team' ? (int) $rule->support_team_id : null,
                    'previous_assignee_id' => $previousAssignee,
                    'previous_support_team_id' => $previousTeam,
                ]);

                $this->auditor->log($ticket, $reason, $context);
            }

            return new SupportTicketAssignment(
                assigneeType: $rule->assignee_type,
                assignee: $rule->assignee,
                team: $rule->team,
                rule: $rule,
                changed: $changed,
                previousAssigneeId: $previousAssignee,
                previousTeamId: $previousTeam,
            );
        }

        return null;
    }

    private function rules(): Collection
    {
        return SupportAssignmentRule::cachedForAssignment();
    }
}
