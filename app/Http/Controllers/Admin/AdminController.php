<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\SearchQueryAggregate;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Traversable;

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
            'queueHealth' => $this->buildQueueHealthMetrics(),
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

    protected function buildQueueHealthMetrics(): array
    {
        $defaultConnection = config('queue.default');
        $connections = config('queue.connections', []);
        $connectionKey = $defaultConnection;
        $connectionConfig = $connections[$connectionKey] ?? [];

        if (($connectionConfig['driver'] ?? null) !== 'database') {
            $fallbackKey = collect($connections)
                ->filter(fn ($config) => ($config['driver'] ?? null) === 'database')
                ->keys()
                ->first();

            if ($fallbackKey) {
                $connectionKey = $fallbackKey;
                $connectionConfig = $connections[$fallbackKey];
            }
        }

        $queueName = $connectionConfig['queue'] ?? 'default';
        $driver = $connectionConfig['driver'] ?? 'database';

        $pendingCount = 0;
        $oldestAvailableAt = null;
        $oldestPendingAgeSeconds = null;

        if ($driver === 'database') {
            $connectionName = $connectionConfig['connection'] ?? config('database.default');
            $jobsTable = $connectionConfig['table'] ?? 'jobs';

            $jobsQuery = DB::connection($connectionName)
                ->table($jobsTable)
                ->when($queueName, fn ($query) => $query->where('queue', $queueName));

            $pendingCount = (clone $jobsQuery)->count();

            $oldestJob = (clone $jobsQuery)->orderBy('available_at')->first();

            if ($oldestJob && isset($oldestJob->available_at)) {
                $availableAt = Carbon::createFromTimestamp((int) $oldestJob->available_at);
                $oldestAvailableAt = $availableAt->toIso8601String();
                $oldestPendingAgeSeconds = max(0, $availableAt->diffInSeconds(now()));
            }
        }

        $failedConfig = config('queue.failed');
        $failedConnection = $failedConfig['database'] ?? config('database.default');
        $failedTable = $failedConfig['table'] ?? 'failed_jobs';

        $failedQuery = DB::connection($failedConnection)->table($failedTable);

        $failedCount = (clone $failedQuery)->count();

        $recentFailures = (clone $failedQuery)
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get()
            ->map(function ($failure) {
                $failedAt = $failure->failed_at ? Carbon::parse($failure->failed_at) : null;

                return [
                    'id' => $failure->id ?? $failure->uuid ?? null,
                    'queue' => $failure->queue ?? null,
                    'connection' => $failure->connection ?? null,
                    'failed_at' => $failedAt?->toIso8601String(),
                    'exception_excerpt' => isset($failure->exception)
                        ? Str::limit($failure->exception, 160)
                        : null,
                ];
            })
            ->all();

        $lastFailure = $recentFailures[0]['failed_at'] ?? null;

        return [
            'connection' => $connectionKey,
            'queue' => $queueName,
            'pending' => $pendingCount,
            'failed' => $failedCount,
            'oldest_pending_available_at' => $oldestAvailableAt,
            'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
            'last_failed_at' => $lastFailure,
            'recent_failures' => $recentFailures,
            'workers' => $this->configuredQueueWorkers($connectionKey, $connectionConfig, $queueName),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function configuredQueueWorkers(string $connectionKey, array $connectionConfig, string $queueName): array
    {
        $configuredWorkers = config('queue.workers');

        if ($configuredWorkers instanceof Arrayable) {
            $configuredWorkers = $configuredWorkers->toArray();
        } elseif ($configuredWorkers instanceof Traversable) {
            $configuredWorkers = iterator_to_array($configuredWorkers);
        } elseif (! is_array($configuredWorkers)) {
            $configuredWorkers = Arr::wrap($configuredWorkers);
        }

        $workers = [];

        foreach (Arr::wrap($configuredWorkers) as $worker) {
            $normalized = $this->normalizeQueueWorker($worker, $connectionKey, $connectionConfig, $queueName);

            if ($normalized !== null) {
                $workers[] = $normalized;
            }
        }

        if ($workers === []) {
            $workers[] = $this->defaultQueueWorker($connectionKey, $connectionConfig, $queueName);
        }

        return $workers;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalizeQueueWorker($worker, string $connectionKey, array $connectionConfig, string $queueName): ?array
    {
        if ($worker instanceof Arrayable) {
            $worker = $worker->toArray();
        } elseif ($worker instanceof Traversable) {
            $worker = iterator_to_array($worker);
        } elseif (is_object($worker)) {
            $worker = (array) $worker;
        }

        if (! is_array($worker) || $worker === []) {
            return null;
        }

        $queues = array_values($this->normalizeQueueList($worker['queues'] ?? [], $queueName));

        $queues = array_map(static fn ($queue) => (string) $queue, $queues);

        return [
            'name' => $worker['name'] ?? ($connectionConfig['queue'] ?? $connectionKey),
            'connection' => $worker['connection'] ?? ($connectionConfig['connection'] ?? $connectionKey),
            'queues' => $queues,
            'tries' => $worker['tries'] ?? ($connectionConfig['tries'] ?? null),
            'backoff' => $worker['backoff'] ?? ($connectionConfig['backoff'] ?? null),
            'sleep' => $worker['sleep'] ?? ($connectionConfig['sleep'] ?? null),
            'timeout' => $worker['timeout'] ?? ($connectionConfig['timeout'] ?? null),
            'max_jobs' => $worker['max_jobs'] ?? ($connectionConfig['max_jobs'] ?? null),
            'max_time' => $worker['max_time'] ?? ($connectionConfig['max_time'] ?? null),
        ];
    }

    protected function defaultQueueWorker(string $connectionKey, array $connectionConfig, string $queueName): array
    {
        $queues = array_values($this->normalizeQueueList($connectionConfig['queues'] ?? [], $queueName));

        $queues = array_map(static fn ($queue) => (string) $queue, $queues);

        return [
            'name' => $connectionKey,
            'connection' => $connectionConfig['connection'] ?? $connectionKey,
            'queues' => $queues,
            'tries' => $connectionConfig['tries'] ?? null,
            'backoff' => $connectionConfig['backoff'] ?? null,
            'sleep' => $connectionConfig['sleep'] ?? null,
            'timeout' => $connectionConfig['timeout'] ?? null,
            'max_jobs' => $connectionConfig['max_jobs'] ?? null,
            'max_time' => $connectionConfig['max_time'] ?? null,
        ];
    }

    protected function normalizeQueueList($queues, string $queueName): array
    {
        if ($queues instanceof Arrayable) {
            $queues = $queues->toArray();
        } elseif ($queues instanceof Traversable) {
            $queues = iterator_to_array($queues);
        } elseif (is_object($queues)) {
            $queues = (array) $queues;
        }

        if (is_string($queues)) {
            $queues = array_map('trim', explode(',', $queues));
        } elseif (! is_array($queues)) {
            $queues = Arr::wrap($queues);
        }

        $normalized = [];

        foreach ($queues as $queue) {
            if (is_string($queue)) {
                $queue = trim($queue);
            } elseif (is_numeric($queue)) {
                $queue = (string) $queue;
            } else {
                continue;
            }

            if ($queue === '') {
                continue;
            }

            $normalized[] = $queue;
        }

        if ($normalized === [] && $queueName !== '') {
            $normalized[] = $queueName;
        }

        return array_values(array_unique($normalized));
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

        $userMonthExpression = $this->monthFormatExpression(User::query()->qualifyColumn('created_at'));

        $userRegistrationsByMonth = User::query()
            ->selectRaw("{$userMonthExpression} as month")
            ->selectRaw('COUNT(*) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $ticketMonthExpression = $this->monthFormatExpression(SupportTicket::query()->qualifyColumn('created_at'));

        $supportTicketsByMonth = SupportTicket::query()
            ->selectRaw("{$ticketMonthExpression} as month")
            ->selectRaw('COUNT(*) as total')
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

    protected function monthFormatExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Gather a concise feed of recent platform activity.
     */
    protected function recentActivities(DateFormatter $formatter): array
    {
        $userActivity = User::latest('created_at')
            ->take(5)
            ->get()
            ->map(function (User $user) use ($formatter) {
                return [
                    'id' => "user-{$user->id}",
                    'activity' => sprintf('User %s registered', $user->nickname ?? $user->name ?? 'unknown user'),
                    'time' => $formatter->human($user->created_at),
                    'timestamp' => $user->created_at,
                ];
            });

        $blogActivity = Blog::latest('published_at')
            ->take(5)
            ->get()
            ->map(function (Blog $blog) use ($formatter) {
                $timestamp = $blog->published_at ?? $blog->created_at;
                $status = $blog->status === 'published' ? 'published' : 'created';

                return [
                    'id' => "blog-{$blog->id}",
                    'activity' => sprintf('Blog "%s" %s', $blog->title, $status),
                    'time' => $formatter->human($timestamp),
                    'timestamp' => $timestamp,
                ];
            });

        $ticketActivity = SupportTicket::query()
            ->orderByDesc(DB::raw('COALESCE(resolved_at, updated_at, created_at)'))
            ->take(5)
            ->get()
            ->map(function (SupportTicket $ticket) use ($formatter) {
                $timestamp = $ticket->resolved_at ?? $ticket->updated_at ?? $ticket->created_at;
                $status = $ticket->status ?? 'updated';

                return [
                    'id' => "ticket-{$ticket->id}",
                    'activity' => sprintf('Ticket "%s" %s', $ticket->subject, $status),
                    'time' => $formatter->human($timestamp),
                    'timestamp' => $timestamp,
                ];
            });

        return collect([$userActivity, $blogActivity, $ticketActivity])
            ->flatten(1)
            ->filter(fn ($activity) => $activity['timestamp'])
            ->sortByDesc('timestamp')
            ->take(8)
            ->map(fn ($activity) => Arr::except($activity, 'timestamp'))
            ->values()
            ->all();
    }
}
