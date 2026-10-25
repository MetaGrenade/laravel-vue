<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Resources\MentionSuggestionResource;
use App\Models\Badge;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use App\Models\User;
use App\Support\Forum\ForumIndexCache;
use App\Support\Forum\ForumSubmissions;
use App\Support\Forum\ThreadPrefixes;
use App\Support\Localization\DateFormatter;
use App\Support\Security\HtmlSanitizer;
use App\Support\Seo\Seo;
use App\Support\Spam\CommentGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ForumController extends Controller
{
    use InteractsWithInertiaPagination;

    public function __construct(private readonly ForumIndexCache $forumIndexCache) {}

    public function index(Request $request, ThreadPrefixes $prefixes): Response
    {
        $formatter = DateFormatter::for($request->user());

        $categories = $this->forumIndexCache->categories();
        $trendingThreads = $this->forumIndexCache->trendingThreads();
        $latestPosts = $this->forumIndexCache->latestPosts();

        app(Seo::class)
            ->title('Community Forum')
            ->description('Join the conversation: ask questions, share ideas and get help from the community.');

        return Inertia::render('Forum', [
            'categories' => $categories->map(function (ForumCategory $category) use ($formatter, $prefixes) {
                return [
                    'id' => $category->id,
                    'title' => $category->title,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'boards' => $category->boards->map(function (ForumBoard $board) use ($formatter, $prefixes) {
                        $latestThread = $board->latestThread;
                        $latestPost = $latestThread?->latestPost;
                        $prefix = $latestThread instanceof ForumThread ? $prefixes->payload($latestThread->prefix) : null;

                        return [
                            'id' => $board->id,
                            'title' => $board->title,
                            'slug' => $board->slug,
                            'description' => $board->description,
                            'thread_count' => $board->threads_count,
                            'post_count' => $board->posts_count,
                            'latest_thread' => $latestThread ? [
                                'id' => $latestThread->id,
                                'title' => $latestThread->title,
                                'slug' => $latestThread->slug,
                                'board_slug' => $board->slug,
                                'prefix' => $prefix,
                                'solved' => $latestThread instanceof ForumThread && $latestThread->solved_post_id !== null,
                                'author' => $latestThread->author?->nickname,
                                'last_reply_author' => $latestPost?->author?->nickname,
                                'last_reply_at' => $formatter->dayDateTime($latestPost?->created_at),
                            ] : null,
                        ];
                    })->values(),
                ];
            })->values(),
            'trendingThreads' => $trendingThreads->map(function (ForumThread $thread) use ($formatter, $prefixes) {
                return [
                    'id' => $thread->id,
                    'title' => $thread->title,
                    'slug' => $thread->slug,
                    'prefix' => $prefixes->payload($thread->prefix),
                    'solved' => $thread->solved_post_id !== null,
                    'board' => [
                        'slug' => $thread->board->slug,
                        'title' => $thread->board->title,
                        'category_title' => $thread->board->category?->title,
                    ],
                    'author' => $thread->author?->nickname,
                    'views' => $thread->views,
                    'replies' => max($thread->posts_count - 1, 0),
                    'last_reply_at' => $formatter->dayDateTime($thread->last_posted_at),
                ];
            })->values(),
            'latestPosts' => $latestPosts->map(function (ForumPost $post) use ($formatter, $prefixes) {
                return [
                    'id' => $post->id,
                    'title' => $post->thread->title,
                    'prefix' => $prefixes->payload($post->thread->prefix),
                    'solved' => $post->thread->solved_post_id !== null,
                    'thread_slug' => $post->thread->slug,
                    'board_slug' => $post->thread->board->slug,
                    'board_title' => $post->thread->board->title,
                    'author' => $post->author?->nickname,
                    'created_at' => $formatter->dayDateTime($post->created_at),
                    'thread_id' => $post->thread->id,
                ];
            })->values(),
        ]);
    }

    public function mentionSuggestions(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $query = isset($validated['q']) ? trim((string) $validated['q']) : '';

        if ($query === '') {
            return response()->json([
                'data' => [],
            ]);
        }

        $escaped = addcslashes($query, '%_');

        $users = User::query()
            ->select('id', 'nickname', 'avatar_url')
            ->whereNotNull('nickname')
            ->where('id', '!=', $user->id)
            ->where(function ($builder) use ($escaped) {
                $builder->whereLike('nickname', $escaped.'%')
                    ->orWhereLike('nickname', '%'.$escaped.'%');
            })
            ->orderByRaw('LOWER(nickname) like ? desc', [Str::lower($escaped).'%'])
            ->orderBy('nickname')
            ->limit(8)
            ->get()
            ->filter(function (User $mentioned) {
                return filled(trim((string) $mentioned->nickname));
            })
            ->values();

        $suggestions = $users
            ->map(function (User $mentioned) use ($request) {
                return (new MentionSuggestionResource($mentioned))->toArray($request);
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $suggestions,
        ]);
    }

    public function showBoard(Request $request, ForumBoard $board, ThreadPrefixes $prefixes): Response
    {
        $board->load('category');

        $search = trim((string) $request->query('search', ''));
        $offered = $prefixes->choices($board);
        $prefixSlug = trim((string) $request->query('prefix', ''));
        $solvedQuery = $request->query('solved');
        $activeSolved = $solvedQuery === 'yes' || $solvedQuery === 'no' ? $solvedQuery : null;
        $activePrefix = null;

        foreach ($offered as $choice) {
            if ($choice['slug'] === $prefixSlug) {
                $activePrefix = $choice;
                break;
            }
        }
        $user = $request->user();
        $isModerator = $user?->hasAnyRole(['admin', 'editor', 'moderator']);

        $formatter = DateFormatter::for($user);

        $includeReads = $user !== null;

        $threadsQuery = $board->threads()
            ->select('forum_threads.*')
            ->leftJoin('users as thread_authors', 'thread_authors.id', '=', 'forum_threads.user_id')
            ->when(! $isModerator, function ($query) {
                $query->where('forum_threads.is_published', true);
            })
            ->when($includeReads, function ($query) use ($user) {
                $query->leftJoin('forum_thread_reads as thread_reads', function ($join) use ($user) {
                    $join->on('thread_reads.forum_thread_id', '=', 'forum_threads.id')
                        ->where('thread_reads.user_id', '=', $user->id);
                })->addSelect([
                    'thread_reads.last_read_at as last_read_at',
                    'thread_reads.last_read_post_id as last_read_post_id',
                ]);
            })
            ->with(['author:id,nickname', 'prefix:id,name,slug,color', 'latestPost.author:id,nickname'])
            ->withCount('posts');

        if ($search !== '') {
            $escaped = addcslashes($search, '%_');
            $likeTerm = "%{$escaped}%";

            $threadsQuery->where(function ($query) use ($likeTerm) {
                $query->whereLike('forum_threads.title', $likeTerm)
                    ->orWhereLike('forum_threads.excerpt', $likeTerm)
                    ->orWhereLike('thread_authors.nickname', $likeTerm)
                    ->orWhereExists(function ($postQuery) use ($likeTerm) {
                        // A table, not the model: the scope that leaves held replies out does not reach it (docs/automod.md).
                        $postQuery->selectRaw('1')
                            ->from('forum_posts')
                            ->whereColumn('forum_posts.forum_thread_id', 'forum_threads.id')
                            ->whereNull('forum_posts.deleted_at')
                            ->whereNull('forum_posts.held_at')
                            ->whereLike('forum_posts.body', $likeTerm);
                    });
            });
        }

        if ($activePrefix !== null) {
            $threadsQuery->where('forum_threads.forum_prefix_id', $activePrefix['id']);
        }

        if ($activeSolved === 'yes') {
            $threadsQuery->whereNotNull('forum_threads.solved_post_id');
        } elseif ($activeSolved === 'no') {
            $threadsQuery->whereNull('forum_threads.solved_post_id');
        }

        $threadsQuery->orderByDesc('forum_threads.is_pinned');

        if ($includeReads) {
            $threadsQuery->orderByDesc(DB::raw('CASE WHEN forum_threads.last_posted_at IS NULL THEN 0 WHEN thread_reads.last_read_at IS NULL THEN 1 WHEN forum_threads.last_posted_at > thread_reads.last_read_at THEN 1 ELSE 0 END'));
        }

        $threadsQuery->orderByDesc('forum_threads.last_posted_at');
        $threadsQuery->orderByDesc('forum_threads.created_at');

        $threads = $threadsQuery
            ->paginate(15)
            ->withQueryString();

        $threadItems = $threads->getCollection()->map(function (ForumThread $thread) use ($user, $isModerator, $formatter, $prefixes) {
            $latestPost = $thread->latestPost;

            $hasUnread = $user !== null && $thread->last_posted_at !== null && (
                $thread->last_read_at === null || $thread->last_posted_at->gt($thread->last_read_at)
            );

            return [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'prefix' => $prefixes->payload($thread->prefix),
                'solved' => $thread->solved_post_id !== null,
                'author' => $thread->author?->nickname,
                'replies' => max($thread->posts_count - 1, 0),
                'views' => $thread->views,
                'is_pinned' => $thread->is_pinned,
                'is_locked' => $thread->is_locked,
                'is_published' => $thread->is_published,
                'has_unread' => $hasUnread,
                'last_reply_author' => $latestPost?->author?->nickname,
                'last_reply_at' => $formatter->dayDateTime($latestPost?->created_at),
                'permissions' => [
                    'canReport' => $user !== null && $user->id !== $thread->user_id,
                    'canModerate' => (bool) $isModerator,
                    'canMarkRead' => $hasUnread,
                ],
            ];
        })->values();

        $reportReasons = collect(config('forum.report_reasons', []))
            ->map(function (array $reason, string $key) {
                return [
                    'value' => $key,
                    'label' => $reason['label'] ?? Str::headline(str_replace('_', ' ', $key)),
                    'description' => $reason['description'] ?? null,
                ];
            })
            ->values();

        $seo = app(Seo::class)
            ->title($board->title.' - Forum')
            ->description($board->description ?: "Discussions in {$board->title}.")
            ->canonical($this->paginatedCanonical(route('forum.boards.show', $board), $request));

        if ($activePrefix !== null || $activeSolved !== null) {
            $seo->noindex();
        }

        return Inertia::render('ForumThreads', [
            'board' => [
                'id' => $board->id,
                'title' => $board->title,
                'slug' => $board->slug,
                'description' => $board->description,
                'category' => [
                    'title' => $board->category?->title,
                    'slug' => $board->category?->slug,
                ],
            ],
            'threads' => array_merge([
                'data' => $threadItems,
            ], $this->inertiaPagination($threads)),
            'filters' => [
                'search' => $search,
                'prefix' => $activePrefix['slug'] ?? null,
                'solved' => $activeSolved,
            ],
            'prefixes' => $offered,
            'permissions' => [
                'canModerate' => (bool) $isModerator,
            ],
            'reportReasons' => $reportReasons,
        ]);
    }

    public function showThread(Request $request, ForumBoard $board, ForumThread $thread, ThreadPrefixes $prefixes): Response
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $user = $request->user();

        $isModerator = $user?->hasAnyRole(['admin', 'editor', 'moderator']);

        $formatter = DateFormatter::for($user);

        if (! $thread->is_published && ! $isModerator) {
            abort(404);
        }

        $sessionKey = 'forum.viewed_threads';
        $now = now();
        $thresholdTimestamp = $now->copy()->subMinutes(5)->getTimestamp();

        $viewedThreads = $request->session()->get($sessionKey, []);
        $viewedThreads = array_filter(
            $viewedThreads,
            static fn ($timestamp) => $timestamp >= $thresholdTimestamp
        );

        $lastViewedAt = $viewedThreads[$thread->id] ?? null;

        if ($lastViewedAt === null || $lastViewedAt < $thresholdTimestamp) {
            $thread->increment('views');
            $viewedThreads[$thread->id] = $now->getTimestamp();
        }

        $request->session()->put($sessionKey, $viewedThreads);

        $board->load('category');

        $thread->load([
            'author:id,nickname,avatar_url,forum_signature',
            'prefix:id,name,slug,color',
            'board.category:id,title,slug',
            'latestPost' => function ($query) {
                $query->select('forum_posts.id', 'forum_posts.forum_thread_id', 'forum_posts.created_at');
            },
        ])->loadCount('subscriptions');

        // A reply that automatic moderation has held (docs/automod.md) is not on the page for anybody but its author, who is shown it as
        // waiting, and the moderators, who see everything.
        $postsPerPage = 10;

        $visiblePosts = $thread->posts()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->when(! $isModerator, fn ($query) => $query->where(function ($visible) use ($user) {
                $visible->whereNull('forum_posts.held_at')
                    ->when($user !== null, fn ($own) => $own->orWhere('forum_posts.user_id', $user->id));
            }));

        $solutionPost = null;
        $solution = null;

        if ($thread->solved_post_id !== null) {
            $candidate = (clone $visiblePosts)->whereKey($thread->solved_post_id)->first();

            if ($candidate instanceof ForumPost) {
                $solutionPost = $candidate;
                $before = (clone $visiblePosts)
                    ->where(function ($query) use ($candidate) {
                        $query->where('forum_posts.created_at', '<', $candidate->created_at)
                            ->orWhere(function ($sameMoment) use ($candidate) {
                                $sameMoment->where('forum_posts.created_at', '=', $candidate->created_at)
                                    ->where('forum_posts.id', '<', $candidate->id);
                            });
                    })
                    ->count();
                $solution = [
                    'post_id' => $candidate->id,
                    'page' => intdiv($before, $postsPerPage) + 1,
                ];
            }
        }

        // The same post as ThreadSolutions: a deleted opening post still counts, so the next reply can be marked or unmarked.
        $openerId = ForumPost::query()
            ->withTrashed()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->where('forum_thread_id', $thread->id)
            ->orderBy('id')
            ->value('id');

        $posts = (clone $visiblePosts)
            ->with(['author' => function ($query) {
                $query->select('id', 'nickname', 'created_at', 'avatar_url', 'forum_signature', 'reputation_points')
                    ->withCount('forumPosts')
                    ->with(['badges' => function ($badgeQuery) {
                        $badgeQuery->select('badges.id', 'badges.name', 'badges.slug', 'badges.description', 'badges.points_required');
                    }]);
            }, 'mentions' => function ($query) {
                $query->select('users.id', 'users.nickname');
            }])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($postsPerPage)
            ->withQueryString();

        $postItems = $posts->getCollection()->map(function (ForumPost $post, int $index) use ($posts, $user, $isModerator, $thread, $formatter) {
            $author = $post->author;

            // A held reply can be neither edited, deleted, reported nor looked up in the history until it is out: those routes do not find it
            // (the scope), and a moderator approves or rejects it in the review queue.
            $held = $post->held_at !== null;
            $canModerate = (bool) $isModerator && ! $held;
            $canEdit = $canModerate;

            if (! $canEdit && ! $held && $user !== null && $user->id === $post->user_id && $thread->is_published && ! $thread->is_locked) {
                $canEdit = true;
            }

            return [
                'id' => $post->id,
                'held' => $held,
                'held_reason' => $isModerator ? $post->held_reason : null,
                'body' => $post->body,
                'body_raw' => $post->body,
                'quote_html' => $this->serialiseQuote($post->body),
                'created_at' => $formatter->dayDateTime($post->created_at),
                'edited_at' => $formatter->dayDateTime($post->edited_at),
                'number' => $posts->firstItem() ? ($posts->firstItem() + $index) : ($index + 1),
                'author' => [
                    'id' => $author?->id,
                    'nickname' => $author?->nickname,
                    'joined_at' => $formatter->date($author?->created_at),
                    'forum_posts_count' => $author?->forum_posts_count ?? 0,
                    'primary_role' => $author?->getRoleNames()->first() ?? 'Member',
                    'avatar_url' => $author?->avatar_url,
                    'forum_signature' => $author?->forum_signature,
                    'reputation_points' => $author?->reputation_points ?? 0,
                    'badges' => ($author?->badges ?? collect())->map(function (Badge $badge) use ($formatter) {
                        $awardedAt = $badge->pivot?->awarded_at;

                        if ($awardedAt !== null && ! $awardedAt instanceof Carbon) {
                            $awardedAt = Carbon::parse($awardedAt);
                        }

                        return [
                            'id' => $badge->id,
                            'name' => $badge->name,
                            'slug' => $badge->slug,
                            'description' => $badge->description,
                            'points_required' => $badge->points_required,
                            'awarded_at' => $formatter->dayDateTime($awardedAt),
                        ];
                    })->values(),
                ],
                'permissions' => [
                    'canReport' => ! $held && $user !== null && $user->id !== $post->user_id,
                    'canEdit' => $canEdit,
                    'canDelete' => ! $held && $user !== null && ($user->id === $post->user_id || $canModerate),
                    'canModerate' => $canModerate,
                ],
                'mentions' => $post->mentions->map(function (User $mentioned) {
                    $profileUrl = null;

                    if (Route::has('members.show')) {
                        $profileUrl = route('members.show', ['user' => $mentioned->getRouteKey()]);
                    }

                    return [
                        'id' => $mentioned->id,
                        'nickname' => $mentioned->nickname,
                        'profile_url' => $profileUrl,
                    ];
                })->values(),
            ];
        })->values();

        if ($user !== null) {
            $latestPost = $thread->latestPost;
            $readAt = now();

            if ($latestPost?->created_at && $latestPost->created_at->greaterThan($readAt)) {
                $readAt = $latestPost->created_at;
            }

            ForumThreadRead::updateOrCreate(
                [
                    'forum_thread_id' => $thread->id,
                    'user_id' => $user->id,
                ],
                [
                    'last_read_post_id' => $latestPost?->id,
                    'last_read_at' => $readAt,
                ],
            );
        }

        $reportReasons = collect(config('forum.report_reasons', []))
            ->map(function (array $reason, string $key) {
                return [
                    'value' => $key,
                    'label' => $reason['label'] ?? Str::headline(str_replace('_', ' ', $key)),
                    'description' => $reason['description'] ?? null,
                ];
            })
            ->values();

        $isSubscribed = $thread->isSubscribedBy($user);

        $canModerateThread = (bool) $isModerator;
        $canMarkSolution = $thread->is_published && $user !== null && (
            $canModerateThread || $user->id === $thread->user_id
        );
        $canEditThread = $user !== null && (
            $canModerateThread || (
                $user->id === $thread->user_id &&
                $thread->is_published &&
                ! $thread->is_locked
            )
        );

        $opener = $thread->posts()->oldest()->first(['id', 'body', 'created_at']);
        $threadUrl = route('forum.threads.show', [$board, $thread]);

        app(Seo::class)
            ->title($thread->title)
            ->description($thread->excerpt ?: $opener?->body)
            ->canonical($this->paginatedCanonical($threadUrl, $request))
            ->type('article')
            ->schema(array_filter([
                '@type' => 'DiscussionForumPosting',
                'headline' => $thread->title,
                'url' => $threadUrl,
                'datePublished' => $thread->created_at?->toAtomString(),
                'dateModified' => ($thread->last_posted_at ?? $thread->updated_at)?->toAtomString(),
                'author' => $thread->author ? ['@type' => 'Person', 'name' => $thread->author->nickname] : null,
                'text' => $opener ? Str::limit(trim(strip_tags($opener->body)), 500) : null,
                'acceptedAnswer' => $solutionPost instanceof ForumPost ? [
                    '@type' => 'Answer',
                    'text' => Str::limit(trim(strip_tags((string) $solutionPost->body)), 500),
                ] : null,
                'interactionStatistic' => [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CommentAction',
                    'userInteractionCount' => max(0, $posts->total() - 1),
                ],
            ]));

        return Inertia::render('ForumThreadView', [
            'board' => [
                'title' => $board->title,
                'slug' => $board->slug,
                'category' => [
                    'title' => $board->category?->title,
                    'slug' => $board->category?->slug,
                ],
            ],
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'prefix' => $prefixes->payload($thread->prefix),
                'opener_post_id' => is_numeric($openerId) ? (int) $openerId : null,
                'solution' => $solution,
                'is_locked' => $thread->is_locked,
                'is_pinned' => $thread->is_pinned,
                'is_published' => $thread->is_published,
                'views' => $thread->views,
                'author' => $thread->author?->nickname,
                'last_posted_at' => $formatter->dayDateTime($thread->last_posted_at),
                'is_subscribed' => $isSubscribed,
                'subscribers_count' => $thread->subscriptions_count,
                'permissions' => [
                    'canModerate' => $canModerateThread,
                    'canEdit' => $canEditThread,
                    'canReport' => $user !== null && $user->id !== $thread->user_id,
                    'canReply' => $user !== null && $thread->is_published && ! $thread->is_locked,
                    'canMarkSolution' => $canMarkSolution,
                ],
            ],
            'posts' => array_merge([
                'data' => $postItems,
            ], $this->inertiaPagination($posts)),
            'reportReasons' => $reportReasons,
            'prefixes' => $prefixes->choices($board),
        ]);
    }

    private function serialiseQuote(string $html): string
    {
        $cleaned = preg_replace('/<(script|style|iframe)[^>]*>.*?<\/\1>/is', '', $html) ?? '';

        $cleaned = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $cleaned);
        $cleaned = preg_replace('/<\/(p|div)>/i', "\n\n", $cleaned) ?? $cleaned;

        $text = trim(strip_tags($cleaned));

        if ($text === '') {
            return '<blockquote><p></p></blockquote><p></p>';
        }

        $paragraphs = array_filter(array_map('trim', preg_split("/\n{2,}/", $text) ?: []));

        if (empty($paragraphs)) {
            $paragraphs = [$text];
        }

        $quoteBody = collect($paragraphs)->map(function (string $paragraph) {
            $escaped = e($paragraph);
            $escaped = str_replace(["\r\n", "\r"], "\n", $escaped);
            $escaped = nl2br($escaped, false);

            return '<p>'.$escaped.'</p>';
        })->implode('');

        return '<blockquote>'.$quoteBody.'</blockquote><p></p>';
    }

    /**
     * Canonical URL for a paginated listing: keeps the page number, drops filters.
     */
    private function paginatedCanonical(string $url, Request $request): string
    {
        $page = (int) $request->query('page', 1);

        return $page > 1 ? $url.'?'.http_build_query(['page' => $page]) : $url;
    }

    public function createThread(Request $request, ForumBoard $board, ThreadPrefixes $prefixes): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $board->load('category');
        $offered = $prefixes->choices($board);

        return Inertia::render('ForumThreadCreate', [
            'board' => [
                'id' => $board->id,
                'title' => $board->title,
                'slug' => $board->slug,
                'description' => $board->description,
                'category' => [
                    'title' => $board->category?->title,
                    'slug' => $board->category?->slug,
                ],
            ],
            'prefixes' => $offered,
            'prefixesRequired' => $board->prefixes_required && $offered !== [],
        ]);
    }

    public function storeThread(Request $request, ForumBoard $board, ForumSubmissions $submissions, ThreadPrefixes $prefixes): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        // The field nobody sees: a form that has it filled in was filled in by a script (docs/automod.md).
        app(CommentGuard::class)->validateHoneypot($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'prefix' => ['nullable', 'string', 'max:255'],
        ]);

        $prefix = $prefixes->resolveId($board, $validated['prefix'] ?? null, (bool) $board->prefixes_required);

        $title = trim((string) $validated['title']);
        $body = trim(app(HtmlSanitizer::class)->forum((string) $validated['body']));
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter some content before publishing.',
            ]);
        }

        $submission = $submissions->thread($user, $board, $title, $body, $prefix);

        // Caught by a rule: kept for a moderator, and the author is told so (the thread is not published, so its page is not theirs to open).
        if ($submission->held) {
            return redirect()
                ->route('forum.boards.show', ['board' => $board->slug])
                ->with('success', 'Your thread has been sent for review. It will appear when a moderator approves it.');
        }

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $submission->thread->slug,
        ])->with('success', 'Thread created successfully.');
    }
}
