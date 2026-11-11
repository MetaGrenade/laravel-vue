<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Rules\ValidDateInterval;
use App\Support\SupportSlaConfiguration;
use App\Support\SupportTicketAuditor;
use App\Support\SupportTicketAutoAssigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MonitorSupportTicketSlas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<string> The times in the settings that could not be read during this run, so each is logged once. */
    private array $unreadable = [];

    public function handle(SupportTicketAutoAssigner $assigner, SupportTicketAuditor $auditor): void
    {
        $escalations = collect(SupportSlaConfiguration::priorityEscalations());
        $reassignThresholds = collect(SupportSlaConfiguration::reassignAfter());

        SupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $tickets) use ($assigner, $auditor, $escalations, $reassignThresholds): void {
                $tickets->each(function (SupportTicket $ticket) use ($assigner, $auditor, $escalations, $reassignThresholds): void {
                    $this->maybeReassign($ticket, $assigner, $reassignThresholds);
                    $this->maybeEscalatePriority($ticket, $escalations, $auditor);
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

        $limit = $this->thresholdTime($threshold);

        if ($limit === null || $ticket->created_at?->greaterThan($limit)) {
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
        Collection $thresholds
    ): void {
        if (! $ticket->assigned_to) {
            return;
        }

        $threshold = $ticket->priority ? $thresholds->get($ticket->priority) : null;

        if (! $threshold) {
            return;
        }

        $limit = $this->thresholdTime($threshold);

        if ($limit === null || $ticket->updated_at?->greaterThan($limit)) {
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

    /**
     * The moment a ticket has to be older than for a rule to apply, or null when the time written in the settings cannot be read. A rule
     * that cannot be read is skipped (and said so once in the log): treating it as "now" would raise or hand over every open ticket at once.
     */
    private function thresholdTime(string $threshold): ?Carbon
    {
        $interval = ValidDateInterval::parse($threshold);

        if ($interval === null) {
            if (! in_array($threshold, $this->unreadable, true)) {
                $this->unreadable[] = $threshold;
                Log::warning('A support SLA time cannot be read, so its rule is skipped. Fix it under Admin → Support → SLA.', ['value' => $threshold]);
            }

            return null;
        }

        return now()->sub($interval);
    }
}
