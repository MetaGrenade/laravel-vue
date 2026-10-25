<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogView;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Commerce\CustomerOrders;
use App\Support\Commerce\Money;
use App\Support\Localization\DateFormatter;
use App\Support\Onboarding\Onboarding;
use App\Support\WebsiteSections;
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

        $formatter = DateFormatter::for($user);

        return Inertia::render('Dashboard', [
            'metrics' => $this->buildMetrics($user),
            'activityChart' => $this->buildActivityChart($user),
            'recentItems' => $this->recentActivity($user, $formatter),
            'recommendedArticles' => $this->recommendedArticles($user, $formatter),
            'shop' => $this->shop($user),
            'plan' => $this->plan($user),
            'account' => $this->account($user),
            'onboarding' => $this->onboarding($user),
        ]);
    }

    /**
     * The customer's orders and digital purchases, or nothing when the shop is switched off (their order
     * pages are then not reachable from the site, so the dashboard does not offer them).
     *
     * @return array<string, mixed>|null
     */
    protected function shop(User $user): ?array
    {
        if (! WebsiteSections::isEnabled(WebsiteSections::COMMERCE)) {
            return null;
        }

        return app(CustomerOrders::class)->dashboard($user);
    }

    /**
     * The "Get started" checklist, until it is done, hidden or too old to show (docs/onboarding.md).
     *
     * @return array<string, mixed>|null
     */
    protected function onboarding(User $user): ?array
    {
        $checklist = app(Onboarding::class)->for($user);

        return $checklist === null ? null : [...$checklist, 'dismiss_url' => route('onboarding.dismiss')];
    }

    /**
     * The user's subscription, if plans are offered or they have one.
     *
     * @return array<string, mixed>|null
     */
    protected function plan(User $user): ?array
    {
        $subscription = $user->subscription((string) config('billing.subscription_name', 'default'));

        if ($subscription === null && ! SubscriptionPlan::query()->active()->exists()) {
            return null;
        }

        $plan = $subscription !== null
            ? SubscriptionPlan::query()->where('stripe_price_id', $subscription->stripe_price)->first()
            : null;

        $state = match (true) {
            $subscription === null => 'none',
            $subscription->onGracePeriod() => 'grace',
            $subscription->pastDue() || $subscription->incomplete() => 'attention',
            $subscription->valid() => 'active',
            default => 'ended',
        };

        return [
            'state' => $state,
            'name' => $plan?->name,
            // Plans store minor units (1200 is $12.00); the page formats a decimal in the currency's major units.
            'price' => $plan !== null ? Money::ofMinor((int) $plan->price, (string) $plan->currency)->toDecimal() : null,
            'currency' => $plan?->currency,
            'interval' => $plan?->interval,
            'ends_at' => $subscription?->ends_at?->toIso8601String(),
        ];
    }

    /**
     * What helps a user look after their account.
     *
     * @return array<string, mixed>
     */
    protected function account(User $user): array
    {
        return [
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'reputation' => (int) $user->reputation_points,
            'badges' => $user->badges()->count(),
            'member_since' => $user->created_at?->toIso8601String(),
        ];
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
        // Nothing from a section that is switched off: its pages are not there to link to.
        $forumOn = WebsiteSections::isEnabled(WebsiteSections::FORUM);
        $supportOn = WebsiteSections::isEnabled(WebsiteSections::SUPPORT);

        $threads = ! $forumOn ? collect() : $user->forumThreads()
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

        $posts = ! $forumOn ? collect() : $user->forumPosts()
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
                        ? route('forum.threads.show', [$board->slug, $thread->slug])."#post-{$post->id}"
                        : null,
                    'timestamp' => $timestamp,
                ];
            });

        $tickets = ! $supportOn ? collect() : SupportTicket::query()
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
        if (! WebsiteSections::isEnabled(WebsiteSections::BLOG)) {
            return [];
        }

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
}
