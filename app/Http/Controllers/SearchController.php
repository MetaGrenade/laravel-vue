<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Handle the aggregated search across blogs, forum threads, and FAQs.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->string('q')->trim();
        $limit = (int) $request->integer('limit', 5);
        $limit = max(1, min($limit, 10));

        if ($query->isEmpty()) {
            return response()->json([
                'query' => '',
                'results' => $this->emptyResults(),
            ]);
        }

        $term = $query->toString();
        $likeTerm = '%' . $term . '%';

        $blogsCollection = Blog::query()
            ->where(function ($query) {
                $query->where('status', 'published')
                    ->orWhere(function ($query) {
                        $query->where('status', 'scheduled')
                            ->whereNotNull('scheduled_for')
                            ->where('scheduled_for', '<=', now());
                    });
            })
            ->where(function ($query) use ($likeTerm) {
                $query->where('title', 'like', $likeTerm)
                    ->orWhere('excerpt', 'like', $likeTerm);
            })
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit($limit + 1)
            ->get(['id', 'title', 'slug', 'excerpt', 'published_at', 'created_at']);

        $blogItems = $blogsCollection
            ->take($limit)
            ->map(function (Blog $blog) {
                $excerpt = is_string($blog->excerpt) ? trim($blog->excerpt) : '';

                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'description' => $excerpt !== '' ? Str::limit($excerpt, 120) : null,
                    'url' => route('blogs.view', ['slug' => $blog->slug]),
                ];
            })
            ->values()
            ->all();

        $threadsCollection = ForumThread::query()
            ->where('is_published', true)
            ->where(function ($query) use ($likeTerm) {
                $query->where('title', 'like', $likeTerm)
                    ->orWhere('excerpt', 'like', $likeTerm);
            })
            ->with(['board:id,slug,title'])
            ->orderByDesc('last_posted_at')
            ->orderByDesc('created_at')
            ->limit($limit + 1)
            ->get(['id', 'forum_board_id', 'title', 'slug', 'excerpt', 'last_posted_at', 'created_at']);

        $threadItems = $threadsCollection
            ->take($limit)
            ->map(function (ForumThread $thread) {
                $board = $thread->board;

                if ($board === null) {
                    return null;
                }

                $excerpt = is_string($thread->excerpt) ? trim($thread->excerpt) : '';

                return [
                    'id' => $thread->id,
                    'title' => $thread->title,
                    'description' => $excerpt !== '' ? Str::limit($excerpt, 120) : null,
                    'url' => route('forum.threads.show', ['board' => $board->slug, 'thread' => $thread->slug]),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $faqCollection = Faq::query()
            ->where('published', true)
            ->where(function ($query) use ($likeTerm) {
                $query->where('question', 'like', $likeTerm)
                    ->orWhere('answer', 'like', $likeTerm);
            })
            ->orderBy('order')
            ->orderBy('question')
            ->limit($limit + 1)
            ->get(['id', 'faq_category_id', 'question', 'answer']);

        $faqItems = $faqCollection
            ->take($limit)
            ->map(function (Faq $faq) use ($term) {
                $answer = is_string($faq->answer) ? trim(strip_tags($faq->answer)) : '';

                $params = ['faqs_search' => $term];

                if ($faq->faq_category_id) {
                    $params['faq_category_id'] = $faq->faq_category_id;
                }

                return [
                    'id' => $faq->id,
                    'title' => $faq->question,
                    'description' => $answer !== '' ? Str::limit($answer, 120) : null,
                    'url' => route('support', $params),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'query' => $term,
            'results' => [
                'blogs' => [
                    'items' => $blogItems,
                    'has_more' => $blogsCollection->count() > $limit,
                ],
                'forum_threads' => [
                    'items' => $threadItems,
                    'has_more' => $threadsCollection->count() > $limit,
                ],
                'faqs' => [
                    'items' => $faqItems,
                    'has_more' => $faqCollection->count() > $limit,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, array{items: array<int, array<string, mixed>>, has_more: bool}>
     */
    private function emptyResults(): array
    {
        return [
            'blogs' => [
                'items' => [],
                'has_more' => false,
            ],
            'forum_threads' => [
                'items' => [],
                'has_more' => false,
            ],
            'faqs' => [
                'items' => [],
                'has_more' => false,
            ],
        ];
    }
}
