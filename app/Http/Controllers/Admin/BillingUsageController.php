<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\Meters;
use App\Support\Usage\Usage;
use App\Support\Usage\UsagePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What has been used of each meter in a month, across every account (see docs/usage.md). Looking needs `billing.acp.view`, as the rest of
 * the billing screens do.
 */
class BillingUsageController extends Controller
{
    /** How many accounts the table of heaviest users shows. */
    private const TOP_ACCOUNTS = 25;

    /** How many months back the month picker goes (events are kept for longer or shorter, by `billing.usage.retention_months`). */
    private const MONTHS_BACK = 12;

    public function __construct(private readonly Meters $meters, private readonly Usage $usage) {}

    public function index(Request $request): Response
    {
        $meters = $this->meters->all();
        $key = array_key_exists((string) $request->query('meter'), $meters) ? (string) $request->query('meter') : array_key_first($meters);
        $period = UsagePeriod::monthOf($this->monthFrom((string) $request->query('month')));

        $props = [
            'meters' => array_values(array_map(fn ($meter) => ['key' => $meter->key, 'label' => $meter->label, 'unit' => $meter->unit], $meters)),
            'meter' => $key,
            'month' => $period->start->format('Y-m'),
            'months' => $this->months(),
            'retention_months' => (int) config('billing.usage.retention_months', 13),
            'totals' => ['units' => 0, 'events' => 0, 'accounts' => 0],
            'daily' => [],
            'top' => [],
        ];

        if ($key === null) {
            return Inertia::render('acp/BillingUsage', $props);
        }

        $events = fn (): Builder => UsageEvent::query()
            ->where('meter', $key)
            ->where('occurred_at', '>=', $period->start)
            ->where('occurred_at', '<', $period->end);

        $props['totals'] = [
            'units' => (int) $events()->sum('quantity'),
            'events' => $events()->count(),
            'accounts' => $events()->distinct()->count('user_id'),
        ];
        $props['daily'] = $this->daily($events(), $period);
        $props['top'] = $this->top($events(), $key, $period);

        return Inertia::render('acp/BillingUsage', $props);
    }

    /**
     * Units used on each day of the month, with the days nothing was used on as zero, so that the picture has no gaps.
     *
     * @return list<array{date: string, units: int}>
     */
    private function daily(Builder $events, UsagePeriod $period): array
    {
        $used = $events
            ->selectRaw('DATE(occurred_at) as day, SUM(quantity) as units')
            ->groupByRaw('DATE(occurred_at)')
            ->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->getAttribute('day'), 0, 10) => (int) $row->getAttribute('units')])
            ->all();

        $days = [];

        for ($date = $period->start; $date < $period->end; $date = $date->addDay()) {
            $days[] = ['date' => $date->toDateString(), 'units' => $used[$date->toDateString()] ?? 0];
        }

        return $days;
    }

    /**
     * The accounts that used the most, and for this month, whether they have used all they may.
     *
     * @return list<array{user: array{id: int, nickname: string, email: string}, units: int, allowance: int|null, exhausted: bool}>
     */
    private function top(Builder $events, string $meter, UsagePeriod $period): array
    {
        $rows = $events
            ->select('user_id', DB::raw('SUM(quantity) as units'))
            ->groupBy('user_id')
            ->orderByDesc('units')
            ->orderBy('user_id')
            ->limit(self::TOP_ACCOUNTS)
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'nickname', 'email'])->keyBy('id');
        $thisMonth = $period->contains(now());

        return $rows
            ->map(function ($row) use ($users, $meter, $thisMonth) {
                // Every event belongs to an account that exists: the events go with it when it is deleted.
                $user = $users[$row->getAttribute('user_id')];
                $units = (int) $row->getAttribute('units');
                // What an account may use is what it may use now, so it says nothing about a month that is over.
                $allowance = $thisMonth ? $this->usage->allowance($user, $meter) : null;

                return [
                    'user' => ['id' => $user->id, 'nickname' => $user->nickname, 'email' => $user->email],
                    'units' => $units,
                    'allowance' => $allowance,
                    'exhausted' => $allowance !== null && $units >= $allowance,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The months the picker offers, newest first.
     *
     * @return list<array{value: string, label: string}>
     */
    private function months(): array
    {
        $months = [];

        for ($ago = 0; $ago < self::MONTHS_BACK; $ago++) {
            $month = UsagePeriod::monthOf(now()->subMonthsNoOverflow($ago))->start;
            $months[] = ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')];
        }

        return $months;
    }

    /**
     * The month asked for (`2026-09`), or this one if the request has none or something else.
     */
    private function monthFrom(string $month): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) !== 1) {
            return CarbonImmutable::now();
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0);
    }
}
