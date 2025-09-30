<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the authenticated user dashboard with live insights.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'metrics' => $this->buildMetrics($user),
            'activityChart' => $this->buildActivityChart($user),
            'recentItems' => $this->recentActivity($user),
            'recommendedArticles' => $this->recommendedArticles(),
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
    protected function recentActivity(User $user): array
    {
        $threads = $user->forumThreads()
            ->with(['board:id,slug'])
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(function (ForumThread $thread) {
                $timestamp = $thread->updated_at ?? $thread->created_at;

                return [
                    'id' => "thread-{$thread->id}",
                    'summary' => sprintf('Updated thread "%s"', $thread->title),
                    'context' => 'Forum thread',
                    'time' => optional($timestamp)->diffForHumans(),
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
            ->map(function (ForumPost $post) {
                $thread = $post->thread;
                $board = $thread?->board;
                $timestamp = $post->created_at;

                return [
                    'id' => "post-{$post->id}",
                    'summary' => $thread
                        ? sprintf('Replied to "%s"', $thread->title)
                        : 'Posted a forum reply',
                    'context' => 'Forum reply',
                    'time' => optional($timestamp)->diffForHumans(),
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
            ->map(function (SupportTicket $ticket) {
                $timestamp = $ticket->updated_at ?? $ticket->created_at;
                $status = $ticket->status ?? 'updated';

                return [
                    'id' => "ticket-{$ticket->id}",
                    'summary' => sprintf('Ticket "%s" %s', $ticket->subject, $status),
                    'context' => 'Support',
                    'time' => optional($timestamp)->diffForHumans(),
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
    protected function recommendedArticles(): array
    {
        return Blog::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(function (Blog $blog) {
                $timestamp = $blog->published_at ?? $blog->created_at;

                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'excerpt' => $blog->excerpt,
                    'url' => route('blogs.view', $blog->slug),
                    'published_at' => optional($timestamp)->toIso8601String(),
                ];
            })
            ->all();
    }
}
