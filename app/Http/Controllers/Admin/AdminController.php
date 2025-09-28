<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /**
     * Show the ACP dashboard with live metrics.
     */
    public function get(Request $request): Response
    {
        $metrics = $this->buildMetricSnapshot();

        return Inertia::render('acp/Dashboard', [
            'metrics' => $metrics,
            'chartData' => $this->buildChartData(),
            'recentActivities' => $this->recentActivities(),
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
    protected function recentActivities(): array
    {
        $userActivity = User::latest('created_at')
            ->take(5)
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => "user-{$user->id}",
                    'activity' => sprintf('User %s registered', $user->nickname ?? $user->name ?? 'unknown user'),
                    'time' => optional($user->created_at)->diffForHumans(),
                    'timestamp' => $user->created_at,
                ];
            });

        $blogActivity = Blog::latest('published_at')
            ->take(5)
            ->get()
            ->map(function (Blog $blog) {
                $timestamp = $blog->published_at ?? $blog->created_at;
                $status = $blog->status === 'published' ? 'published' : 'created';

                return [
                    'id' => "blog-{$blog->id}",
                    'activity' => sprintf('Blog "%s" %s', $blog->title, $status),
                    'time' => optional($timestamp)->diffForHumans(),
                    'timestamp' => $timestamp,
                ];
            });

        $ticketActivity = SupportTicket::latest('updated_at')
            ->take(5)
            ->get()
            ->map(function (SupportTicket $ticket) {
                $timestamp = $ticket->updated_at ?? $ticket->created_at;
                $status = $ticket->status ?? 'updated';

                return [
                    'id' => "ticket-{$ticket->id}",
                    'activity' => sprintf('Ticket "%s" %s', $ticket->subject, $status),
                    'time' => optional($timestamp)->diffForHumans(),
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
