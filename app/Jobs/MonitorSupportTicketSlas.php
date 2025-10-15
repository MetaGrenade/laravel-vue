<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Support\SupportSlaConfiguration;
use App\Support\SupportTicketAutoAssigner;
use App\Support\SupportTicketAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MonitorSupportTicketSlas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SupportTicketAutoAssigner $assigner, SupportTicketAuditor $auditor): void
    {
        $escalations = collect(SupportSlaConfiguration::priorityEscalations());
        $reassignThresholds = collect(SupportSlaConfiguration::reassignAfter());

        SupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $tickets) use ($assigner, $auditor, $escalations, $reassignThresholds): void {
                $tickets->each(function (SupportTicket $ticket) use ($assigner, $auditor, $escalations, $reassignThresholds): void {
                    $priorityBeforeActions = $ticket->priority;

                    $this->maybeEscalatePriority($ticket, $escalations, $auditor);
                    $this->maybeReassign($ticket, $assigner, $reassignThresholds, $priorityBeforeActions);
                });
            });
    }

    private function maybeEscalatePriority(SupportTicket $ticket, Collection $escalations, SupportTicketAuditor $auditor): void
    {
        $rule = $escalations->get($ticket->priority);

        if (! is_array($rule)) {
            return;
        }

        $targetPriority = $rule['to'] ?? null;
        $threshold = $rule['after'] ?? null;

        if (! $targetPriority || $targetPriority === $ticket->priority || ! $threshold) {
            return;
        }

        if ($ticket->created_at?->greaterThan($this->thresholdTime($threshold))) {
            return;
        }

        $previousPriority = $ticket->priority;

        $ticket->forceFill(['priority' => $targetPriority])->save();

        $auditor->log($ticket, 'priority_escalated', [
            'from' => $previousPriority,
            'to' => $targetPriority,
            'threshold' => $threshold,
        ]);
    }

    private function maybeReassign(
        SupportTicket $ticket,
        SupportTicketAutoAssigner $assigner,
        Collection $thresholds,
        ?string $priorityForThreshold = null
    ): void
    {
        if (! $ticket->assigned_to) {
            return;
        }

        $priorityKey = $priorityForThreshold ?: $ticket->priority;
        $threshold = $priorityKey ? $thresholds->get($priorityKey) : null;

        if (! $threshold) {
            return;
        }

        if ($ticket->updated_at?->greaterThan($this->thresholdTime($threshold))) {
            return;
        }

        $assigner->assign($ticket, [
            'exclude' => [(int) $ticket->assigned_to],
            'reason' => 'sla_reassigned',
            'meta' => [
                'threshold' => $threshold,
            ],
        ]);
    }

    private function thresholdTime(string $threshold): Carbon
    {
        $interval = @\DateInterval::createFromDateString($threshold);

        if (! $interval) {
            return now();
        }

        return now()->sub($interval);
    }
}
