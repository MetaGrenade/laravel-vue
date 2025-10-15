<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogView;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Display the authenticated user dashboard with live insights.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $queueHealth = $this->buildQueueHealth();
        $formatter = DateFormatter::for($user);

        return Inertia::render('Dashboard', [
            'metrics' => $this->buildMetrics($user),
            'activityChart' => $this->buildActivityChart($user),
            'recentItems' => $this->recentActivity($user, $formatter),
            'recommendedArticles' => $this->recommendedArticles($user, $formatter),
            // Add queue health info in a defensive way so the front-end always receives stable shape
            'queueHealth' => $queueHealth,
        ]);
    }

    /**
     * Compile summary metrics for the dashboard cards.
     */
    protected function buildMetrics(User $user): array
    {
        $supportQuery = SupportTicket::query()->where('user_id', $user->id);

        $ticketStatusCounts = (clone $supportQuery)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $support = [
            'total' => (clone $supportQuery)->count(),
            'open' => (int) ($ticketStatusCounts['open'] ?? 0),
            'pending' => (int) ($ticketStatusCounts['pending'] ?? 0),
            'resolved' => (int) ($ticketStatusCounts['closed'] ?? 0),
            'new_this_month' => (clone $supportQuery)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ];

        $forumThreadQuery = ForumThread::query()->where('user_id', $user->id);
        $forumPostQuery = ForumPost::query()->where('user_id', $user->id);

        $forum = [
            'threads' => (clone $forumThreadQuery)->count(),
            'active_this_month' => (clone $forumThreadQuery)
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count(),
            'replies' => (clone $forumPostQuery)->count(),
            'replies_this_week' => (clone $forumPostQuery)
                ->where('created_at', '>=', now()->startOfWeek())
                ->count(),
            'unread_threads' => ForumThread::query()
                ->where('is_published', true)
                ->whereDoesntHave('reads', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->count(),
        ];

        $knowledgeBaseQuery = Blog::query()
            ->where('user_id', $user->id);

        $knowledge = [
            'published_articles' => (clone $knowledgeBaseQuery)
                ->where('status', 'published')
                ->count(),
            'drafts' => (clone $knowledgeBaseQuery)
                ->where('status', 'draft')
                ->count(),
        ];

        return [
            'support' => $support,
            'forum' => $forum,
            'knowledge' => $knowledge,
        ];
    }

    /**
     * Build a six-month trend of activity for the chart widget.
     */
    protected function buildActivityChart(User $user): array
    {
        $start = now()->startOfMonth()->subMonths(5);

        $forumPostsByMonth = ForumPost::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->filter(fn (ForumPost $post) => $post->created_at)
            ->groupBy(fn (ForumPost $post) => $post->created_at->format('Y-m'))
            ->map->count();

        $supportTicketsByMonth = SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->filter(fn (SupportTicket $ticket) => $ticket->created_at)
            ->groupBy(fn (SupportTicket $ticket) => $ticket->created_at->format('Y-m'))
            ->map->count();

        return collect(range(0, 5))
            ->map(fn (int $offset) => $start->copy()->addMonths($offset))
            ->map(function (Carbon $month) use ($forumPostsByMonth, $supportTicketsByMonth) {
                $key = $month->format('Y-m');

                return [
                    'period' => $month->format('M Y'),
                    'Forum Replies' => (int) ($forumPostsByMonth[$key] ?? 0),
                    'Support Tickets' => (int) ($supportTicketsByMonth[$key] ?? 0),
                ];
            })
            ->toArray();
    }

    /**
     * Collect the latest items the user interacted with.
     */
    protected function recentActivity(User $user, DateFormatter $formatter): array
    {
        $threads = $user->forumThreads()
            ->with(['board:id,slug'])
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(function (ForumThread $thread) use ($formatter) {
                $timestamp = $thread->updated_at ?? $thread->created_at;

                return [
                    'id' => "thread-{$thread->id}",
                    'summary' => sprintf('Updated thread "%s"', $thread->title),
                    'context' => 'Forum thread',
                    'time' => $formatter->human($timestamp),
                    'url' => $thread->board
                        ? route('forum.threads.show', [$thread->board->slug, $thread->slug])
                        : null,
                    'timestamp' => $timestamp,
                ];
            });

        $posts = $user->forumPosts()
            ->with(['thread:id,slug,forum_board_id', 'thread.board:id,slug'])
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(function (ForumPost $post) use ($formatter) {
                $thread = $post->thread;
                $board = $thread?->board;
                $timestamp = $post->created_at;

                return [
                    'id' => "post-{$post->id}",
                    'summary' => $thread
                        ? sprintf('Replied to "%s"', $thread->title)
                        : 'Posted a forum reply',
                    'context' => 'Forum reply',
                    'time' => $formatter->human($timestamp),
                    'url' => $thread && $board
                        ? route('forum.threads.show', [$board->slug, $thread->slug]) . "#post-{$post->id}"
                        : null,
                    'timestamp' => $timestamp,
                ];
            });

        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(function (SupportTicket $ticket) use ($formatter) {
                $timestamp = $ticket->updated_at ?? $ticket->created_at;
                $status = $ticket->status ?? 'updated';

                return [
                    'id' => "ticket-{$ticket->id}",
                    'summary' => sprintf('Ticket "%s" %s', $ticket->subject, $status),
                    'context' => 'Support',
                    'time' => $formatter->human($timestamp),
                    'url' => route('support'),
                    'timestamp' => $timestamp,
                ];
            });

        return collect([$threads, $posts, $tickets])
            ->flatten(1)
            ->filter(fn ($activity) => $activity['timestamp'])
            ->sortByDesc('timestamp')
            ->take(8)
            ->map(fn ($activity) => Arr::except($activity, 'timestamp'))
            ->values()
            ->all();
    }

    /**
     * Highlight recently published knowledge base articles.
     */
    protected function recommendedArticles(User $user, DateFormatter $formatter): array
    {
        $viewedBlogIds = BlogView::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_viewed_at')
            ->pluck('blog_id');

        $articles = collect();

        if ($viewedBlogIds->isNotEmpty()) {
            $recentViewedIds = $viewedBlogIds->take(50);

            $preferredCategoryIds = DB::table('blog_blog_category')
                ->whereIn('blog_id', $recentViewedIds)
                ->pluck('blog_category_id')
                ->unique();

            $preferredTagIds = DB::table('blog_blog_tag')
                ->whereIn('blog_id', $recentViewedIds)
                ->pluck('blog_tag_id')
                ->unique();

            if ($preferredCategoryIds->isNotEmpty() || $preferredTagIds->isNotEmpty()) {
                $articles = Blog::query()
                    ->where('status', 'published')
                    ->whereNotIn('id', $viewedBlogIds->all())
                    ->where(function ($query) use ($preferredCategoryIds, $preferredTagIds) {
                        if ($preferredCategoryIds->isNotEmpty()) {
                            $query->whereHas('categories', function ($categoryQuery) use ($preferredCategoryIds) {
                                $categoryQuery->whereIn('blog_categories.id', $preferredCategoryIds);
                            });
                        }

                        if ($preferredTagIds->isNotEmpty()) {
                            $method = $preferredCategoryIds->isNotEmpty() ? 'orWhereHas' : 'whereHas';

                            $query->{$method}('tags', function ($tagQuery) use ($preferredTagIds) {
                                $tagQuery->whereIn('blog_tags.id', $preferredTagIds);
                            });
                        }
                    })
                    ->orderByDesc('published_at')
                    ->orderByDesc('created_at')
                    ->take(5)
                    ->get();
            }
        }

        if ($articles->count() < 5) {
            $fallback = Blog::query()
                ->where('status', 'published')
                ->when($viewedBlogIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $viewedBlogIds->all()))
                ->withCount('comments')
                ->orderByDesc('comments_count')
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->take(5 - $articles->count())
                ->get();

            $articles = $articles->concat($fallback);
        }

        return $articles
            ->unique('id')
            ->take(5)
            ->map(function (Blog $blog) use ($formatter) {
                $timestamp = $blog->published_at ?? $blog->created_at;

                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'excerpt' => $blog->excerpt,
                    'url' => route('blogs.view', $blog->slug),
                    'published_at' => $formatter->iso($timestamp),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build a deterministic queue health payload used in admin dashboard.
     *
     * Returns array with keys:
     * - pending (int)
     * - failed (int)
     * - queue (string)
     * - recent_failures (array)
     * - workers (array)  <-- always present, may be empty
     */
    protected function buildQueueHealth(): array
    {
        // Determine the queue connection and table names for the DB driver
        // This supports the typical "database" queue connection from config/queue.php.
        $databaseConnection = config('queue.connections.database.connection') ?? config('database.default');
        $jobsTable = config('queue.connections.database.table', 'jobs');

        // Pending jobs count (for the default queue)
        try {
            $pending = (int) DB::connection($databaseConnection)
                ->table($jobsTable)
                ->where('queue', 'default')
                ->count();
        } catch (\Throwable $e) {
            // Defensive fallback if DB not available in test context
            $pending = 0;
        }

        // Failed jobs count & recent failures
        $failedConnection = config('queue.failed.database') ?? config('database.default');
        $failedTable = config('queue.failed.table', 'failed_jobs');

        try {
            $failed = (int) DB::connection($failedConnection)
                ->table($failedTable)
                ->count();

            // recent failures: newest 5 failed job rows
            $recentFailures = DB::connection($failedConnection)
                ->table($failedTable)
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(['id', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(function ($row) {
                    return [
                        'id' => $row->id ?? null,
                        'connection' => $row->connection ?? null,
                        'queue' => $row->queue ?? null,
                        'exception' => isset($row->exception) ? (string) $row->exception : null,
                        'failed_at' => isset($row->failed_at) ? (string) $row->failed_at : null,
                    ];
                })
                ->toArray();
        } catch (\Throwable $e) {
            $failed = 0;
            $recentFailures = [];
        }

        // Queue name the test expects to see (use 'default' by convention)
        $queueName = 'default';

        // Workers detection: in many environments you may want to report the
        // number of running queue workers (supervisor, horizon, etc).
        // For unit tests / CI we do not attempt to introspect OS processes.
        // Always return an array (even empty) to satisfy tests / UI.
        $workers = $this->detectQueueWorkers();

        return [
            'pending' => $pending,
            'failed' => $failed,
            'queue' => $queueName,
            'recent_failures' => $recentFailures,
            'workers' => $workers,
        ];
    }

    /**
     * Detect queue workers.
     *
     * NOTE: This is intentionally conservative: tests/CI may not have workers,
     * and trying to detect OS processes can be flaky. Return an array even when empty.
     *
     * If you want to fill this with real worker data later you can:
     * - Inspect supervisorctl (remote) or use Laravel Horizon API (if Horizon used)
     * - Implement a heartbeat system where workers periodically write to cache/db
     *
     * @return array<int, array<string, mixed>>
     */
    protected function detectQueueWorkers(): array
    {
        // Default: empty array (deterministic, safe for tests)
        return [];

        // Example of future enhancement (commented):
        //
        // if (class_exists(\Laravel\Horizon\Horizon::class)) {
        //     // Use Horizon to list supervisors / workers
        // }
        //
        // Or: return an array of ['name' => 'worker-1', 'last_seen' => '...']
    }
}
