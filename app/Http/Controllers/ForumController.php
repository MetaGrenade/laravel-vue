<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadRead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ForumController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $categories = ForumCategory::query()
            ->visibleTo($user)
            ->with(['boards' => function ($query) {
                $query->withCount([
                    'publishedThreads as threads_count',
                    'posts as posts_count' => function ($postQuery) {
                        $postQuery->where('forum_threads.is_published', true);
                    },
                ])->with(['latestThread' => function ($threadQuery) {
                    $threadQuery->with(['author:id,nickname', 'latestPost.author:id,nickname'])
                        ->withCount('posts');
                }]);
            }])
            ->orderBy('position')
            ->get();

        $visibleCategoryIds = $categories->pluck('id');

        $trendingThreads = ForumThread::query()
            ->where('is_published', true)
            ->whereHas('board.category', function ($query) use ($visibleCategoryIds) {
                $query->whereIn('forum_categories.id', $visibleCategoryIds);
            })
            ->with(['board:id,slug,title,forum_category_id', 'board.category:id,slug,title', 'author:id,nickname'])
            ->withCount('posts')
            ->orderByDesc('is_pinned')
            ->orderByDesc('views')
            ->orderByDesc('last_posted_at')
            ->limit(5)
            ->get();

        $latestPosts = ForumPost::query()
            ->whereHas('thread', function ($query) {
                $query->where('is_published', true);
            })
            ->whereHas('thread.board.category', function ($query) use ($visibleCategoryIds) {
                $query->whereIn('forum_categories.id', $visibleCategoryIds);
            })
            ->with([
                'thread:id,slug,title,forum_board_id',
                'thread.board:id,slug,title,forum_category_id',
                'author:id,nickname,created_at',
            ])
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Forum', [
            'categories' => $categories->map(function (ForumCategory $category) {
                return [
                    'id' => $category->id,
                    'title' => $category->title,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'boards' => $category->boards->map(function (ForumBoard $board) {
                        $latestThread = $board->latestThread;
                        $latestPost = $latestThread?->latestPost;

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
                                'author' => $latestThread->author?->nickname,
                                'last_reply_author' => $latestPost?->author?->nickname,
                                'last_reply_at' => $latestPost?->created_at?->toDayDateTimeString(),
                            ] : null,
                        ];
                    })->values(),
                ];
            })->values(),
            'trendingThreads' => $trendingThreads->map(function (ForumThread $thread) {
                return [
                    'id' => $thread->id,
                    'title' => $thread->title,
                    'slug' => $thread->slug,
                    'board' => [
                        'slug' => $thread->board->slug,
                        'title' => $thread->board->title,
                        'category_title' => $thread->board->category?->title,
                    ],
                    'author' => $thread->author?->nickname,
                    'views' => $thread->views,
                    'replies' => max($thread->posts_count - 1, 0),
                    'last_reply_at' => optional($thread->last_posted_at)->toDayDateTimeString(),
                ];
            })->values(),
            'latestPosts' => $latestPosts->map(function (ForumPost $post) {
                return [
                    'id' => $post->id,
                    'title' => $post->thread->title,
                    'thread_slug' => $post->thread->slug,
                    'board_slug' => $post->thread->board->slug,
                    'board_title' => $post->thread->board->title,
                    'author' => $post->author?->nickname,
                    'created_at' => $post->created_at->toDayDateTimeString(),
                    'thread_id' => $post->thread->id,
                ];
            })->values(),
        ]);
    }

    public function showBoard(Request $request, ForumBoard $board): Response
    {
        $this->ensureBoardAccessible($request, $board);

        $board->loadMissing('category');

        $search = trim((string) $request->query('search', ''));

        $user = $request->user();
        $isModerator = $user?->hasAnyRole(['admin', 'editor', 'moderator']);

        $includeReads = $user !== null;

        $threadsQuery = $board->threads()
            ->select('forum_threads.*')
            ->when(!$isModerator, function ($query) {
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
            ->with(['author:id,nickname', 'latestPost.author:id,nickname'])
            ->withCount('posts');

        if ($search !== '') {
            $threadsQuery->where('forum_threads.title', 'like', "%{$search}%");
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

        $threadItems = $threads->getCollection()->map(function (ForumThread $thread) use ($user, $isModerator) {
            $latestPost = $thread->latestPost;

            $hasUnread = $user !== null && $thread->last_posted_at !== null && (
                $thread->last_read_at === null || $thread->last_posted_at->gt($thread->last_read_at)
            );

            return [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'author' => $thread->author?->nickname,
                'replies' => max($thread->posts_count - 1, 0),
                'views' => $thread->views,
                'is_pinned' => $thread->is_pinned,
                'is_locked' => $thread->is_locked,
                'is_published' => $thread->is_published,
                'has_unread' => $hasUnread,
                'last_reply_author' => $latestPost?->author?->nickname,
                'last_reply_at' => $latestPost?->created_at?->toDayDateTimeString(),
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
            ],
            'permissions' => [
                'canModerate' => (bool) $isModerator,
            ],
            'reportReasons' => $reportReasons,
        ]);
    }

    public function showThread(Request $request, ForumBoard $board, ForumThread $thread): Response
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $this->ensureBoardAccessible($request, $board);

        $user = $request->user();

        $isModerator = $user?->hasAnyRole(['admin', 'editor', 'moderator']);

        if (!$thread->is_published && !$isModerator) {
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

        $board->loadMissing('category');

        $thread->load([
            'author:id,nickname,avatar_url,forum_signature',
            'board.category:id,title,slug',
            'latestPost' => function ($query) {
                $query->select('forum_posts.id', 'forum_posts.forum_thread_id', 'forum_posts.created_at');
            },
        ]);

        $posts = $thread->posts()
            ->with(['author' => function ($query) {
                $query->select('id', 'nickname', 'created_at', 'avatar_url', 'forum_signature')
                    ->withCount('forumPosts');
            }])
            ->orderBy('created_at')
            ->paginate(10)
            ->withQueryString();

        $postItems = $posts->getCollection()->map(function (ForumPost $post, int $index) use ($posts, $user, $isModerator, $thread) {
            $author = $post->author;

            $canModerate = (bool) $isModerator;
            $canEdit = $canModerate;

            if (!$canEdit && $user !== null && $user->id === $post->user_id && $thread->is_published && !$thread->is_locked) {
                $canEdit = true;
            }

            return [
                'id' => $post->id,
                'body' => $post->body,
                'body_raw' => $post->body,
                'created_at' => $post->created_at->toDayDateTimeString(),
                'edited_at' => optional($post->edited_at)?->toDayDateTimeString(),
                'number' => $posts->firstItem() ? ($posts->firstItem() + $index) : ($index + 1),
                'author' => [
                    'id' => $author?->id,
                    'nickname' => $author?->nickname,
                    'joined_at' => $author?->created_at?->toFormattedDateString(),
                    'forum_posts_count' => $author?->forum_posts_count ?? 0,
                    'primary_role' => $author?->getRoleNames()->first() ?? 'Member',
                    'avatar_url' => $author?->avatar_url,
                    'forum_signature' => $author?->forum_signature,
                ],
                'permissions' => [
                    'canReport' => $user !== null && $user->id !== $post->user_id,
                    'canEdit' => $canEdit,
                    'canDelete' => $user !== null && ($user->id === $post->user_id || $canModerate),
                    'canModerate' => $canModerate,
                ],
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

        $canModerateThread = (bool) $isModerator;
        $canEditThread = $user !== null && (
            $canModerateThread || (
                $user->id === $thread->user_id &&
                $thread->is_published &&
                !$thread->is_locked
            )
        );

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
                'is_locked' => $thread->is_locked,
                'is_pinned' => $thread->is_pinned,
                'is_published' => $thread->is_published,
                'views' => $thread->views,
                'author' => $thread->author?->nickname,
                'last_posted_at' => optional($thread->last_posted_at)->toDayDateTimeString(),
                'permissions' => [
                    'canModerate' => $canModerateThread,
                    'canEdit' => $canEditThread,
                    'canReport' => $user !== null && $user->id !== $thread->user_id,
                    'canReply' => $user !== null && $thread->is_published && !$thread->is_locked,
                ],
            ],
            'posts' => array_merge([
                'data' => $postItems,
            ], $this->inertiaPagination($posts)),
            'reportReasons' => $reportReasons,
        ]);
    }

    public function createThread(Request $request, ForumBoard $board): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $this->ensureBoardAccessible($request, $board);

        $board->loadMissing('category');

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
        ]);
    }

    public function storeThread(Request $request, ForumBoard $board): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $this->ensureBoardAccessible($request, $board);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $title = trim((string) $validated['title']);
        $body = trim((string) $validated['body']);
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter some content before publishing.',
            ]);
        }

        $baseSlug = Str::slug($title);
        if ($baseSlug === '') {
            $baseSlug = 'thread';
        }

        $baseSlug = Str::limit($baseSlug, 240, '');

        do {
            $slug = $baseSlug . '-' . Str::random(6);
        } while (ForumThread::where('slug', $slug)->exists());

        $thread = null;

        $initialPost = null;

        DB::transaction(function () use ($board, $user, $title, $slug, $body, $bodyText, &$thread, &$initialPost) {
            $excerptSource = $bodyText;

            $thread = ForumThread::create([
                'forum_board_id' => $board->id,
                'user_id' => $user->id,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => Str::limit($excerptSource, 160),
                'last_posted_at' => now(),
                'last_post_user_id' => $user->id,
            ]);

            $initialPost = ForumPost::create([
                'forum_thread_id' => $thread->id,
                'user_id' => $user->id,
                'body' => $body,
            ]);

            $thread->forceFill([
                'last_posted_at' => $initialPost->created_at,
                'last_post_user_id' => $initialPost->user_id,
            ])->save();
        });

        if ($initialPost !== null) {
            ForumThreadRead::updateOrCreate(
                [
                    'forum_thread_id' => $thread->id,
                    'user_id' => $user->id,
                ],
                [
                    'last_read_post_id' => $initialPost->id,
                    'last_read_at' => $initialPost->created_at ?? now(),
                ],
            );
        }

        return redirect()->route('forum.threads.show', [
            'board' => $board->slug,
            'thread' => $thread->slug,
        ])->with('success', 'Thread created successfully.');
    }

    private function ensureBoardAccessible(Request $request, ForumBoard $board): void
    {
        $board->loadMissing('category');

        $category = $board->category;

        abort_if($category === null || !$category->canBeViewedBy($request->user()), 404);
    }
}
