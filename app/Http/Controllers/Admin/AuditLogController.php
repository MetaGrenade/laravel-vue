<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin → Audit log: what has been done in the admin area, by whom, to what, and how it went (see docs/audit-log.md). Only looking:
 * there is nothing here to change an entry with.
 */
class AuditLogController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $formatter = DateFormatter::for($request->user());

        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $search = trim((string) $request->query('search', ''));
        $event = trim((string) $request->query('event', ''));
        $outcome = trim((string) $request->query('outcome', ''));
        $actor = $request->query('actor');
        $account = $request->query('account');
        $from = $this->day((string) $request->query('from', ''), $formatter->timezone(), false);
        $to = $this->day((string) $request->query('to', ''), $formatter->timezone(), true);

        $query = AuditLog::query();

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->whereLike('actor_label', "%{$search}%")
                    ->orWhereLike('subject_label', "%{$search}%")
                    ->orWhereLike('event', "%{$search}%")
                    ->orWhereLike('ip_address', "%{$search}%");
            });
        }

        if ($event !== '') {
            $query->where('event', $event);
        }

        if (in_array($outcome, [AuditLog::SUCCEEDED, AuditLog::FAILED, AuditLog::DENIED], true)) {
            $query->where('outcome', $outcome);
        }

        if (is_numeric($actor)) {
            $query->where('actor_id', (int) $actor);
        }

        // Everything done on one person's account: to them, or to something of theirs.
        if (is_numeric($account)) {
            $query->where('owner_type', User::class)->where('owner_id', (int) $account);
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->withQueryString();

        $accountUser = is_numeric($account) ? User::query()->find((int) $account, ['id', 'nickname', 'email']) : null;

        return Inertia::render('acp/AuditLog', [
            'entries' => array_merge([
                'data' => $entries->getCollection()->map(fn (AuditLog $entry) => [
                    'id' => $entry->id,
                    'created_at' => $formatter->iso($entry->created_at),
                    'actor' => ['id' => $entry->actor_id, 'label' => $entry->actor_label],
                    'event' => $entry->event,
                    'event_label' => Audit::describe($entry->event),
                    'outcome' => $entry->outcome,
                    'subject' => $entry->subject_type === null ? null : [
                        'type' => class_basename($entry->subject_type),
                        'id' => $entry->subject_id,
                        'label' => $entry->subject_label,
                    ],
                    'ip_address' => $entry->ip_address,
                    'user_agent' => $entry->user_agent,
                    'changes' => $entry->changes ?? [],
                    'meta' => $entry->meta ?? (object) [],
                ])->values()->all(),
            ], $this->inertiaPagination($entries)),
            'filters' => [
                'search' => $search,
                'event' => $event,
                'outcome' => $outcome,
                'actor' => is_numeric($actor) ? (string) (int) $actor : '',
                'account' => is_numeric($account) ? (string) (int) $account : '',
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
                'per_page' => $perPage,
            ],
            // Who is being looked at, when the page was opened from a person's account.
            'account' => $accountUser ? ['id' => $accountUser->id, 'nickname' => $accountUser->nickname, 'email' => $accountUser->email] : null,
            'events' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event')
                ->map(fn (string $name) => ['value' => $name, 'label' => Audit::describe($name)])->values()->all(),
            'actors' => AuditLog::query()
                ->whereNotNull('actor_id')
                ->selectRaw('actor_id, MAX(actor_label) as label')
                ->groupBy('actor_id')
                ->orderBy('label')
                ->limit(200)
                ->get()
                ->map(fn ($row) => ['id' => (int) $row->getAttribute('actor_id'), 'label' => (string) $row->getAttribute('label')])
                ->values()
                ->all(),
            'outcomes' => [AuditLog::SUCCEEDED, AuditLog::FAILED, AuditLog::DENIED],
        ]);
    }

    /**
     * The start or the end of a day the person typed, in their own time zone (null if it is not a date).
     */
    private function day(string $value, string $timezone, bool $endOfDay): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        // 2026-02-31 is not a day, though PHP will roll it over into March.
        if (! $day instanceof Carbon || $day->format('Y-m-d') !== $value) {
            return null;
        }

        return ($endOfDay ? $day->endOfDay() : $day->startOfDay())->utc();
    }
}
