<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GlobalSearchMeilisearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
        ]);

        $this->app->make(EngineManager::class)->extend(
            'meilisearch',
            fn () => new CollectionEngine,
        );
    }

    #[Test]
    public function it_returns_results_across_all_groups_from_the_index(): void
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
        $board = $this->board();
        $thread = $this->thread($board, $author, 'Searchable Thread', 'Thread excerpt about Laravel testing.');

        $response = $this->get(route('search.results', ['q' => 'Searchable']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('query', 'Searchable')
            ->where('filters.types', ['blogs', 'forum_threads', 'faqs'])
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.id', $blog->id)
            ->where('results.forum_threads.meta.total', 1)
            ->where('results.forum_threads.items.0.id', $thread->id)
            ->where('results.faqs.meta.total', 1)
            ->where('results.faqs.items.0.id', $faq->id)
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function it_leaves_unpublished_records_out(): void
    {
        Blog::factory()->published()->create(['title' => 'Visible Searchable']);
        Blog::factory()->create(['title' => 'Hidden Searchable']);

        $board = $this->board();
        $author = User::factory()->create();
        $this->thread($board, $author, 'Visible Searchable Thread', published: true);
        $this->thread($board, $author, 'Hidden Searchable Thread', published: false);

        $category = FaqCategory::factory()->create();
        Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'Visible Searchable FAQ?',
            'answer' => 'Published answer.',
            'order' => 1,
            'published' => true,
        ]);
        Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'Hidden Searchable FAQ?',
            'answer' => 'Draft answer.',
            'order' => 2,
            'published' => false,
        ]);

        $response = $this->get(route('search.results', ['q' => 'Searchable']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.title', 'Visible Searchable')
            ->where('results.forum_threads.meta.total', 1)
            ->where('results.forum_threads.items.0.title', 'Visible Searchable Thread')
            ->where('results.faqs.meta.total', 1)
            ->where('results.faqs.items.0.title', 'Visible Searchable FAQ?')
        );
    }

    #[Test]
    public function it_supports_type_filters_and_pagination(): void
    {
        Carbon::setTestNow('2024-02-01 12:00:00');

        for ($i = 1; $i <= 4; $i++) {
            Blog::factory()->published()->create([
                'title' => "Searchable Blog {$i}",
                'slug' => "searchable-blog-{$i}",
                'excerpt' => "Excerpt {$i}",
                'published_at' => now()->subMinutes($i),
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $category = FaqCategory::factory()->create();
        Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'Searchable FAQ only?',
            'answer' => 'Only FAQs should appear when filtered.',
            'order' => 1,
            'published' => true,
        ]);

        $filtered = $this->get(route('search.results', [
            'q' => 'Searchable',
            'types' => ['faqs'],
        ]));

        $filtered->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('filters.types', ['faqs'])
            ->where('results.blogs.meta.total', 0)
            ->where('results.forum_threads.meta.total', 0)
            ->where('results.faqs.meta.total', 1)
        );

        $paged = $this->get(route('search.results', [
            'q' => 'Searchable',
            'types' => ['blogs'],
            'per_page' => 2,
            'blogs_page' => 2,
        ]));

        $paged->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('filters.per_page', 2)
            ->where('results.blogs.meta.current_page', 2)
            ->where('results.blogs.meta.total', 4)
            ->where('results.blogs.items', fn ($items) => count($items) === 2)
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function it_highlights_matches_including_forum_reply_text(): void
    {
        $author = User::factory()->create(['nickname' => 'helper']);
        $blog = Blog::factory()->published()->create([
            'title' => 'Wizarding World of Search',
            'slug' => 'wizarding-world',
            'excerpt' => 'A primer on magical search.',
            'body' => 'Search tips for power users.',
            'user_id' => $author->id,
        ]);

        $board = $this->board();
        $thread = $this->thread($board, $author, 'Ask anything', 'Community AMA session.');

        ForumPost::query()->create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => 'Please ask the wizard anything about indexing.',
        ]);

        $response = $this->get(route('search.results', [
            'q' => 'wizard',
            'types' => ['blogs', 'forum_threads'],
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.blogs.items.0.id', $blog->id)
            ->where('results.blogs.items.0.highlight.title', fn ($value) => is_string($value) && str_contains(strtolower($value), '<mark>wizard</mark>'))
            ->where('results.forum_threads.items.0.id', $thread->id)
            ->where('results.forum_threads.items.0.highlight.description', fn ($value) => is_string($value) && str_contains(strtolower($value), '<mark>wizard</mark>'))
        );
    }

    #[Test]
    public function it_empties_groups_when_their_website_section_is_off(): void
    {
        Blog::factory()->published()->create(['title' => 'Searchable Blog']);
        $category = FaqCategory::factory()->create();
        Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'Searchable FAQ?',
            'answer' => 'Answer',
            'order' => 1,
            'published' => true,
        ]);

        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::BLOG] = false;
        $sections[WebsiteSections::SUPPORT] = false;
        SystemSetting::set('website_sections', $sections);

        $board = $this->board();
        $this->thread($board, User::factory()->create(), 'Searchable Thread');

        $response = $this->get(route('search.results', ['q' => 'Searchable']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.blogs.meta.total', 0)
            ->where('results.faqs.meta.total', 0)
            ->where('results.forum_threads.meta.total', 1)
        );
    }

    #[Test]
    public function the_header_json_endpoint_uses_the_meilisearch_path(): void
    {
        Blog::factory()->published()->create([
            'title' => 'Searchable Blog Post',
            'excerpt' => 'Header search result.',
        ]);

        $response = $this->getJson(route('search', ['q' => 'Searchable']));

        $response->assertOk()
            ->assertJsonPath('query', 'Searchable')
            ->assertJsonPath('results.blogs.has_more', false)
            ->assertJsonPath('results.blogs.items.0.title', 'Searchable Blog Post')
            ->assertJsonCount(1, 'results.blogs.items');
    }

    #[Test]
    public function faq_results_deep_link_without_literal_search_filter(): void
    {
        $category = FaqCategory::factory()->create();
        $faq = Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'How do I reset my password?',
            'answer' => 'Use the forgot password form.',
            'order' => 1,
            'published' => true,
        ]);

        $response = $this->get(route('search.results', [
            'q' => 'password',
            'types' => ['faqs'],
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Search/Results')
            ->where('results.faqs.items.0.id', $faq->id)
            ->where('results.faqs.items.0.url', fn ($url) => is_string($url)
                && str_contains($url, 'faq='.$faq->id)
                && ! str_contains($url, 'faqs_search='))
        );

        $this->get(route('support', [
            'faq' => $faq->id,
            'faq_category_id' => $category->id,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Support')
            ->where('faqs.matchingCount', 1)
            ->where('faqs.groups.0.faqs.0.id', $faq->id)
        );
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::create([
            'title' => 'General Category',
            'slug' => 'general-category-'.uniqid(),
            'position' => 1,
        ]);

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'General Board',
            'slug' => 'general-board-'.uniqid(),
            'description' => 'Discussions about everything.',
            'position' => 1,
        ]);
    }

    private function thread(
        ForumBoard $board,
        User $author,
        string $title,
        string $excerpt = 'Thread excerpt.',
        bool $published = true,
    ): ForumThread {
        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'excerpt' => $excerpt,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => $published,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
            'created_at' => now(),
        ]);
    }
}
