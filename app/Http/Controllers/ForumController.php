<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ForumController extends Controller
{
    public function index(): Response
    {
        $categories = ForumCategory::query()
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

        $trendingThreads = ForumThread::query()
            ->where('is_published', true)
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
        $board->load('category');

        $search = trim((string) $request->query('search', ''));

        $isModerator = $request->user()?->hasAnyRole(['admin', 'editor', 'moderator']);

        $threadsQuery = $board->threads()
            ->when(!$isModerator, function ($query) {
                $query->where('is_published', true);
            })
            ->with(['author:id,nickname', 'latestPost.author:id,nickname'])
            ->withCount('posts');

        if ($search !== '') {
            $threadsQuery->where('title', 'like', "%{$search}%");
        }

        $threads = $threadsQuery
            ->paginate(15)
            ->withQueryString();

        $threadItems = $threads->getCollection()->map(function (ForumThread $thread) {
            $latestPost = $thread->latestPost;

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
                'last_reply_author' => $latestPost?->author?->nickname,
                'last_reply_at' => $latestPost?->created_at?->toDayDateTimeString(),
            ];
        })->values();

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
            'threads' => [
                'data' => $threadItems,
                'meta' => [
                    'current_page' => $threads->currentPage(),
                    'from' => $threads->firstItem(),
                    'last_page' => $threads->lastPage(),
                    'per_page' => $threads->perPage(),
                    'to' => $threads->lastItem(),
                    'total' => $threads->total(),
                ],
                'links' => [
                    'first' => $threads->url(1),
                    'last' => $threads->url($threads->lastPage()),
                    'prev' => $threads->previousPageUrl(),
                    'next' => $threads->nextPageUrl(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
            'permissions' => [
                'canModerate' => (bool) $isModerator,
            ],
        ]);
    }

    public function showThread(Request $request, ForumBoard $board, ForumThread $thread): Response
    {
        abort_if($thread->forum_board_id !== $board->id, 404);

        $isModerator = $request->user()?->hasAnyRole(['admin', 'editor', 'moderator']);

        if (!$thread->is_published && !$isModerator) {
            abort(404);
        }

        $board->load('category');

        $thread->load(['author:id,nickname', 'board.category:id,title,slug']);

        $posts = $thread->posts()
            ->with(['author' => function ($query) {
                $query->select('id', 'nickname', 'created_at')->withCount('forumPosts');
            }])
            ->orderBy('created_at')
            ->paginate(10)
            ->withQueryString();

        $postItems = $posts->getCollection()->map(function (ForumPost $post, int $index) use ($posts) {
            $author = $post->author;

            return [
                'id' => $post->id,
                'body' => $post->body,
                'created_at' => $post->created_at->toDayDateTimeString(),
                'edited_at' => optional($post->edited_at)?->toDayDateTimeString(),
                'number' => $posts->firstItem() ? ($posts->firstItem() + $index) : ($index + 1),
                'signature' => null,
                'author' => [
                    'id' => $author?->id,
                    'nickname' => $author?->nickname,
                    'joined_at' => $author?->created_at?->toFormattedDateString(),
                    'forum_posts_count' => $author?->forum_posts_count ?? 0,
                    'primary_role' => $author?->getRoleNames()->first() ?? 'Member',
                    'avatar_url' => null,
                ],
            ];
        })->values();

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
                'views' => $thread->views,
                'author' => $thread->author?->nickname,
                'last_posted_at' => optional($thread->last_posted_at)->toDayDateTimeString(),
            ],
            'posts' => [
                'data' => $postItems,
                'meta' => [
                    'current_page' => $posts->currentPage(),
                    'from' => $posts->firstItem(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'to' => $posts->lastItem(),
                    'total' => $posts->total(),
                ],
                'links' => [
                    'first' => $posts->url(1),
                    'last' => $posts->url($posts->lastPage()),
                    'prev' => $posts->previousPageUrl(),
                    'next' => $posts->nextPageUrl(),
                ],
            ],
        ]);
    }
}
