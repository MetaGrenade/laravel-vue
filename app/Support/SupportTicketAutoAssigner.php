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

            if (! $rule->assignee) {
                continue;
            }

            if ($rule->support_ticket_category_id && (int) $ticket->support_ticket_category_id !== (int) $rule->support_ticket_category_id) {
                continue;
            }

            if ($rule->priority && $rule->priority !== $ticket->priority) {
                continue;
            }

            if (in_array((int) $rule->assigned_to, $exclude, true)) {
                continue;
            }

            $previousAssignee = $ticket->assigned_to ? (int) $ticket->assigned_to : null;

            $ticket->forceFill(['assigned_to' => $rule->assigned_to]);

            $changed = $ticket->isDirty('assigned_to');

            if ($changed) {
                $ticket->save();
            }

            $ticket->setRelation('assignee', $rule->assignee);

            if ($changed) {
                $context = array_merge($meta, [
                    'rule_id' => $rule->id,
                    'assigned_to' => (int) $rule->assigned_to,
                    'previous_assignee_id' => $previousAssignee,
                ]);

                $this->auditor->log($ticket, $reason, $context);
            }

            return new SupportTicketAssignment(
                assignee: $rule->assignee,
                rule: $rule,
                changed: $changed,
                previousAssigneeId: $previousAssignee,
            );
        }

        return null;
    }

    private function rules(): Collection
    {
        return SupportAssignmentRule::query()
            ->with('assignee:id,nickname,email')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }
}
