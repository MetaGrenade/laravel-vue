<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchResultsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_results_across_all_groups(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $blog = Blog::factory()->published()->create([
            'title' => 'Searchable Blog Post',
            'slug' => 'searchable-blog-post',
            'excerpt' => 'Insights about Laravel testing strategies.',
            'published_at' => now(),
            'created_at' => now(),
        ]);

        $faqCategory = FaqCategory::factory()->create([
            'name' => 'General Knowledge',
            'slug' => 'general-knowledge',
            'description' => 'General knowledge base entries.',
            'order' => 1,
        ]);

        $faq = Faq::create([
            'faq_category_id' => $faqCategory->id,
            'question' => 'Searchable FAQ?',
            'answer' => 'An answer that mentions searchable content.',
            'order' => 1,
            'published' => true,
        ]);

        $author = User::factory()->create();

        $category = ForumCategory::create([
            'title' => 'General Category',
            'slug' => 'general-category',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'General Board',
            'slug' => 'general-board',
            'description' => 'Discussions about everything.',
            'position' => 1,
        ]);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Searchable Thread',
            'slug' => 'searchable-thread',
            'excerpt' => 'Thread excerpt about Laravel testing.',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('search.results', ['q' => 'Searchable']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('query', 'Searchable')
            ->where('filters.types', ['blogs', 'forum_threads', 'faqs'])
            ->where('filters.per_page', 10)
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.id', $blog->id)
            ->where('results.forum_threads.meta.total', 1)
            ->where('results.forum_threads.items.0.id', $thread->id)
            ->where('results.faqs.meta.total', 1)
            ->where('results.faqs.items.0.id', $faq->id)
            ->where('min_query_length', 2)
        );

        Carbon::setTestNow();
    }

    public function test_it_supports_independent_pagination_per_group(): void
    {
        Carbon::setTestNow('2024-02-01 12:00:00');

        for ($i = 1; $i <= 6; $i++) {
            Blog::factory()->published()->create([
                'title' => "Searchable Blog {$i}",
                'slug' => "searchable-blog-{$i}",
                'excerpt' => "Excerpt {$i}",
                'published_at' => now()->subMinutes($i),
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $faqCategory = FaqCategory::create([
            'name' => 'Guides',
            'slug' => 'guides',
            'description' => 'Helpful guides.',
            'order' => 1,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            Faq::create([
                'faq_category_id' => $faqCategory->id,
                'question' => "Searchable FAQ {$i}?",
                'answer' => "Helpful answer {$i}.",
                'order' => $i,
                'published' => true,
            ]);
        }

        $author = User::factory()->create();
        $category = ForumCategory::create([
            'title' => 'Tech',
            'slug' => 'tech',
            'position' => 1,
        ]);
        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Tech Board',
            'slug' => 'tech-board',
            'description' => 'Tech discussions.',
            'position' => 1,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            ForumThread::create([
                'forum_board_id' => $board->id,
                'user_id' => $author->id,
                'title' => "Searchable Thread {$i}",
                'slug' => "searchable-thread-{$i}",
                'excerpt' => "Thread excerpt {$i}.",
                'is_locked' => false,
                'is_pinned' => false,
                'is_published' => true,
                'views' => 0,
                'last_posted_at' => now()->subMinutes($i),
                'last_post_user_id' => $author->id,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->get(route('search.results', [
            'q' => 'Searchable',
            'per_page' => 2,
            'blogs_page' => 2,
            'forum_threads_page' => 3,
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('filters.per_page', 2)
            ->where('results.blogs.meta.current_page', 2)
            ->where('results.blogs.items', function ($items) {
                $titles = collect($items)->pluck('title');

                return $titles->values()->all() === ['Searchable Blog 3', 'Searchable Blog 4'];
            })
            ->where('results.forum_threads.meta.current_page', 3)
            ->where('results.forum_threads.items', function ($items) {
                $titles = collect($items)->pluck('title');

                return $titles->values()->all() === ['Searchable Thread 5', 'Searchable Thread 6'];
            })
            ->where('results.faqs.meta.total', 6)
            ->where('results.faqs.meta.current_page', 1)
        );

        Carbon::setTestNow();
    }

    public function test_it_applies_type_filters(): void
    {
        Carbon::setTestNow('2024-03-01 09:00:00');

        Blog::factory()->published()->create([
            'title' => 'Searchable Blog Entry',
            'slug' => 'searchable-blog-entry',
            'excerpt' => 'Blog excerpt for filtering.',
            'published_at' => now(),
            'created_at' => now(),
        ]);

        $faqCategory = FaqCategory::create([
            'name' => 'Policies',
            'slug' => 'policies',
            'description' => 'Policy FAQs.',
            'order' => 1,
        ]);

        $faq = Faq::create([
            'faq_category_id' => $faqCategory->id,
            'question' => 'Searchable FAQ about policies?',
            'answer' => 'Policy answer content.',
            'order' => 1,
            'published' => true,
        ]);

        $author = User::factory()->create();
        $category = ForumCategory::create([
            'title' => 'Announcements',
            'slug' => 'announcements',
            'position' => 1,
        ]);
        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements Board',
            'slug' => 'announcements-board',
            'description' => 'Official updates.',
            'position' => 1,
        ]);

        ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Searchable Announcement',
            'slug' => 'searchable-announcement',
            'excerpt' => 'Announcement excerpt.',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('search.results', [
            'q' => 'Searchable',
            'types' => ['faqs'],
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('filters.types', ['faqs'])
            ->where('results.blogs.meta.total', 0)
            ->where('results.blogs.items', [])
            ->where('results.forum_threads.meta.total', 0)
            ->where('results.forum_threads.items', [])
            ->where('results.faqs.meta.total', 1)
            ->where('results.faqs.items.0.id', $faq->id)
        );

        Carbon::setTestNow();
    }

    public function test_results_are_ranked_with_highlights_across_types(): void
    {
        Carbon::setTestNow('2024-04-01 08:00:00');

        $primaryAuthor = User::factory()->create([
            'name' => 'Search Wizard',
            'nickname' => 'wizard',
        ]);

        $secondaryAuthor = User::factory()->create([
            'name' => 'Helper Author',
            'nickname' => 'helper',
        ]);

        $featuredBlog = Blog::factory()->published()->create([
            'title' => 'Wizarding World of Search',
            'slug' => 'wizarding-world',
            'excerpt' => 'A primer on magical search.',
            'body' => 'Search wizard tips and tricks for power users.',
            'user_id' => $primaryAuthor->id,
            'published_at' => now(),
            'created_at' => now(),
        ]);

        $bodyMatchBlog = Blog::factory()->published()->create([
            'title' => 'General guide',
            'slug' => 'general-guide',
            'excerpt' => 'No keyword here.',
            'body' => 'This article explains how a wizard might approach indexing.',
            'user_id' => $secondaryAuthor->id,
            'published_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);

        $category = ForumCategory::create([
            'title' => 'Search Updates',
            'slug' => 'search-updates',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'News Board',
            'slug' => 'news-board',
            'description' => 'Announcements and updates.',
            'position' => 1,
        ]);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $primaryAuthor->id,
            'title' => 'Ask the wizard anything',
            'slug' => 'wizard-ama',
            'excerpt' => 'Community AMA session.',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $primaryAuthor->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('search.results', [
            'q' => 'wizard',
            'types' => ['blogs', 'forum_threads'],
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.blogs.items.0.id', $featuredBlog->id)
            ->where('results.blogs.items.0.highlight.title', fn ($value) => is_string($value) && str_contains(strtolower($value), '<mark>wizard</mark>'))
            ->where('results.blogs.items.1.id', $bodyMatchBlog->id)
            ->where('results.blogs.items.1.highlight.description', fn ($value) => is_string($value) && str_contains(strtolower($value), '<mark>wizard</mark>'))
            ->where('results.forum_threads.items.0.id', $thread->id)
            ->where('results.forum_threads.items.0.highlight.description', fn ($value) => is_string($value) && str_contains(strtolower($value), '<mark>wizard</mark>'))
        );

        Carbon::setTestNow();
    }
}
