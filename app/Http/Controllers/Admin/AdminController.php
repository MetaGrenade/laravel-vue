<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\SearchQueryAggregate;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AdminController extends Controller
{
    /**
     * Show the ACP dashboard with live metrics.
     */
    public function get(Request $request): Response
    {
        $metrics = $this->buildMetricSnapshot();
        $formatter = DateFormatter::for($request->user());

        return Inertia::render('acp/Dashboard', [
            'metrics' => $metrics,
            'chartData' => $this->buildChartData(),
            'slaMetrics' => $this->buildSlaMetrics(),
            'recentActivities' => $this->recentActivities($formatter),
            'searchInsights' => $this->buildSearchInsights($formatter),
        ]);
    }

    /**
     * Compile the headline metrics for the dashboard cards.
     */
    protected function buildMetricSnapshot(): array
    {
        $userTotals = [
            'total' => User::count(),
            'new_this_week' => User::where('created_at', '>=', now()->startOfWeek())->count(),
        ];

        $blogTotals = [
            'total' => Blog::count(),
            'published' => Blog::where('status', 'published')->count(),
        ];

        $ticketCounts = SupportTicket::select([
                DB::raw('status'),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tickets = [
            'total' => SupportTicket::count(),
            'open' => (int) ($ticketCounts['open'] ?? 0),
            'closed' => (int) ($ticketCounts['closed'] ?? 0),
            'pending' => (int) ($ticketCounts['pending'] ?? 0),
            'new_this_week' => SupportTicket::where('created_at', '>=', now()->startOfWeek())->count(),
        ];

        return [
            'users' => $userTotals,
            'blogs' => $blogTotals,
            'tickets' => $tickets,
        ];
    }

    protected function buildSearchInsights(DateFormatter $formatter): array
    {
        $topLimit = max(1, (int) config('search.queries.top_queries_limit', 5));
        $topZeroLimit = max(1, (int) config('search.queries.top_zero_queries_limit', 5));

        $topQueries = SearchQueryAggregate::query()
            ->orderByDesc('total_count')
            ->limit($topLimit)
            ->get(['term', 'total_count', 'zero_result_count', 'last_ran_at'])
            ->map(fn (SearchQueryAggregate $aggregate) => [
                'term' => $aggregate->term,
                'total_count' => $aggregate->total_count,
                'zero_result_count' => $aggregate->zero_result_count,
                'last_ran_at' => $formatter->iso($aggregate->last_ran_at),
            ])
            ->all();

        $topZeroQueries = SearchQueryAggregate::query()
            ->where('zero_result_count', '>', 0)
            ->orderByDesc('zero_result_count')
            ->orderByDesc('last_ran_at')
            ->limit($topZeroLimit)
            ->get(['term', 'total_count', 'zero_result_count', 'last_ran_at'])
            ->map(fn (SearchQueryAggregate $aggregate) => [
                'term' => $aggregate->term,
                'total_count' => $aggregate->total_count,
                'zero_result_count' => $aggregate->zero_result_count,
                'last_ran_at' => $formatter->iso($aggregate->last_ran_at),
            ])
            ->all();

        $lastAggregatedAt = SearchQueryAggregate::query()->max('updated_at');

        return [
            'top_queries' => $topQueries,
            'top_zero_queries' => $topZeroQueries,
            'zero_result_total' => (int) SearchQueryAggregate::query()->sum('zero_result_count'),
            'last_aggregated_at' => $lastAggregatedAt
                ? $formatter->iso(Carbon::parse($lastAggregatedAt))
                : null,
        ];
    }

    /**
     * Assemble support specific SLA metrics for the dashboard.
     */
    protected function buildSlaMetrics(): array
    {
        return [
            'queue_aging' => $this->buildQueueAgingMetrics(),
            'pending_volume' => $this->buildPendingVolumeMetrics(),
            'response_times' => $this->buildResponseTimeMetrics(),
        ];
    }

    /**
     * Summarise the age of tickets that are still in the queue.
     */
    protected function buildQueueAgingMetrics(): array
    {
        $now = now();

        $openTickets = SupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->select(['id', 'created_at'])
            ->get();

        $buckets = [
            'under_1_day' => 0,
            'one_to_three_days' => 0,
            'three_to_seven_days' => 0,
            'over_seven_days' => 0,
        ];

        foreach ($openTickets as $ticket) {
            if (! $ticket->created_at) {
                continue;
            }

            $hoursOpen = $ticket->created_at->diffInHours($now);

            if ($hoursOpen < 24) {
                $buckets['under_1_day']++;
            } elseif ($hoursOpen < 72) {
                $buckets['one_to_three_days']++;
            } elseif ($hoursOpen < 168) {
                $buckets['three_to_seven_days']++;
            } else {
                $buckets['over_seven_days']++;
            }
        }

        return $buckets;
    }

    /**
     * Calculate current pending volume and the short term trend of new pending tickets.
     */
    protected function buildPendingVolumeMetrics(): array
    {
        $pendingQuery = SupportTicket::query()->where('status', 'pending');

        $totalPending = (clone $pendingQuery)->count();

        $pendingByPriority = (clone $pendingQuery)
            ->select([
                'priority',
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        $start = now()->startOfDay()->subDays(6);

        $pendingTicketsByDay = SupportTicket::query()
            ->select([
                DB::raw("DATE(created_at) as day"),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->where('status', 'pending')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $trend = collect(range(0, 6))
            ->map(function (int $offset) use ($start, $pendingTicketsByDay) {
                $day = $start->copy()->addDays($offset);
                $key = $day->format('Y-m-d');

                return [
                    'period' => $day->format('M j'),
                    'Pending Tickets' => (int) ($pendingTicketsByDay[$key] ?? 0),
                ];
            })
            ->toArray();

        return [
            'total' => $totalPending,
            'by_priority' => [
                'low' => (int) ($pendingByPriority['low'] ?? 0),
                'medium' => (int) ($pendingByPriority['medium'] ?? 0),
                'high' => (int) ($pendingByPriority['high'] ?? 0),
            ],
            'trend' => $trend,
        ];
    }

    /**
     * Calculate average first response and resolution times alongside their trends.
     */
    protected function buildResponseTimeMetrics(): array
    {
        $rangeStart = now()->startOfWeek()->subWeeks(7);

        $tickets = SupportTicket::query()
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at');
            }])
            ->where('created_at', '>=', $rangeStart)
            ->get();

        $responseMinutes = [];
        $trendBuckets = [];

        foreach ($tickets as $ticket) {
            $firstResponse = $ticket->messages->first(function ($message) use ($ticket) {
                return $message->user_id && $message->user_id !== $ticket->user_id;
            });

            if (! $firstResponse || ! $ticket->created_at) {
                continue;
            }

            $minutes = $ticket->created_at->diffInMinutes($firstResponse->created_at);

            $responseMinutes[] = $minutes;

            $bucketKey = $firstResponse->created_at->copy()->startOfWeek()->format('Y-m-d');

            if (! array_key_exists($bucketKey, $trendBuckets)) {
                $trendBuckets[$bucketKey] = [
                    'minutes' => [],
                ];
            }

            $trendBuckets[$bucketKey]['minutes'][] = $minutes;
        }

        $trend = collect(range(0, 7))
            ->map(function (int $offset) use ($rangeStart, $trendBuckets) {
                $weekStart = $rangeStart->copy()->addWeeks($offset);
                $key = $weekStart->format('Y-m-d');

                $minutes = $trendBuckets[$key]['minutes'] ?? [];
                $averageHours = empty($minutes)
                    ? 0.0
                    : round(array_sum($minutes) / count($minutes) / 60, 1);

                return [
                    'period' => $weekStart->format('M j'),
                    'Average First Response (hrs)' => $averageHours,
                ];
            })
            ->toArray();

        $averageFirstResponseHours = empty($responseMinutes)
            ? null
            : round(array_sum($responseMinutes) / count($responseMinutes) / 60, 1);

        $resolutionMinutes = SupportTicket::query()
            ->where('status', 'closed')
            ->whereNotNull('created_at')
            ->whereNotNull('resolved_at')
            ->get()
            ->map(function (SupportTicket $ticket) {
                return $ticket->created_at->diffInMinutes($ticket->resolved_at);
            })
            ->filter(fn ($minutes) => $minutes !== null);

        $averageResolutionHours = $resolutionMinutes->isEmpty()
            ? null
            : round($resolutionMinutes->avg() / 60, 1);

        return [
            'average_first_response_hours' => $averageFirstResponseHours,
            'average_resolution_hours' => $averageResolutionHours,
            'trend' => $trend,
        ];
    }

    /**
     * Build the chart payload showing monthly registrations and ticket volume.
     */
    protected function buildChartData(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $userRegistrationsByMonth = User::select([
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as total'),
            ])
            ->where('created_at', '>=', $start)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $supportTicketsByMonth = SupportTicket::select([
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as total'),
            ])
            ->where('created_at', '>=', $start)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        return collect(range(0, 11))
            ->map(fn (int $offset) => $start->copy()->addMonths($offset))
            ->map(function (Carbon $month) use ($userRegistrationsByMonth, $supportTicketsByMonth) {
                $key = $month->format('Y-m');

                return [
                    'period' => $month->format('M Y'),
                    'Support Tickets' => (int) ($supportTicketsByMonth[$key] ?? 0),
                    'New User Registrations' => (int) ($userRegistrationsByMonth[$key] ?? 0),
                ];
            })
            ->toArray();
    }

    /**
     * Gather a concise feed of recent platform activity.
     */
    protected function recentActivities(DateFormatter $formatter): array
    {
        $activities = Activity::query()
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        if ($activities->isEmpty()) {
            return [];
        }

        return $activities
            ->map(function (Activity $activity) use ($formatter) {
                $description = $activity->description;

                if (! $description) {
                    $description = $activity->event
                        ? Str::headline(str_replace('.', ' ', $activity->event))
                        : 'Activity recorded';
                }

                return [
                    'id' => "activity-{$activity->id}",
                    'activity' => $description,
                    'time' => $formatter->human($activity->created_at),
                ];
            })
            ->values()
            ->all();
    }
}
