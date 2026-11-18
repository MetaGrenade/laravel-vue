<?php

namespace Tests\Feature\Search;

use App\Jobs\RefreshSearchDocuments;
use App\Models\Blog;
use App\Models\Brand;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use App\Support\Search\CascadedDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Jobs\MakeSearchable;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\KeepingSearchEngine;
use Tests\TestCase;

/**
 * A search document carries the name of something else: the nickname of the member who wrote a thread or a blog post (`author`), the
 * brand of a product (`brand`), the category of a FAQ (`category`). It is made from the rows when it is sent, and renaming the member,
 * the brand or the category changes a row nobody asks the index about, so the document kept the old name: the new one found nothing, and
 * the old one found what no longer had it (docs/search-index.md).
 */
class RefreshedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private KeepingSearchEngine $engine;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $engine = new KeepingSearchEngine;
        $this->app->make(EngineManager::class)->extend('keeping', fn () => $engine);
        config(['scout.driver' => 'keeping', 'scout.queue' => false]);
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::query()->create(['title' => 'Category '.++$this->sequence, 'slug' => 'category-'.$this->sequence, 'description' => 'x', 'position' => $this->sequence]);

        return ForumBoard::query()->create(['forum_category_id' => $category->id, 'title' => 'Board '.++$this->sequence, 'slug' => 'board-'.$this->sequence, 'description' => 'x', 'position' => $this->sequence]);
    }

    private function thread(ForumBoard $board, User $author, bool $published = true): ForumThread
    {
        $title = 'A thread '.++$this->sequence;

        return ForumThread::query()->create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => '',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => $published,
            'views' => 0,
            'last_posted_at' => now(),
        ]);
    }

    private function faq(FaqCategory $category, bool $published = true): Faq
    {
        return Faq::query()->create(['faq_category_id' => $category->id, 'question' => 'Question '.++$this->sequence, 'answer' => 'An answer', 'published' => $published, 'order' => $this->sequence]);
    }

    private function product(?Brand $brand, bool $active = true): Product
    {
        return Product::factory()->create(['brand_id' => $brand?->id, 'is_active' => $active]);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * What the index holds for a model, by key.
     *
     * @param  class-string  $model
     * @return array<int, array<string, mixed>>
     */
    private function documents(string $model): array
    {
        return $this->engine->documents[$model] ?? [];
    }

    #[Test]
    public function the_threads_and_blog_posts_of_a_member_who_changes_their_nickname_are_sent_again_with_it(): void
    {
        $member = User::factory()->create(['nickname' => 'Ada']);
        $other = User::factory()->create(['nickname' => 'Alan']);
        $board = $this->board();

        $mine = [$this->thread($board, $member), $this->thread($board, $member)];
        $theirs = $this->thread($board, $other);
        $unpublished = $this->thread($board, $member, published: false);
        $blog = Blog::factory()->published()->create(['user_id' => $member->id, 'title' => 'Mine']);
        $theirBlog = Blog::factory()->published()->create(['user_id' => $other->id, 'title' => 'Theirs']);

        $this->assertSame('Ada', $this->documents(ForumThread::class)[$mine[0]->id]['author']);

        $member->update(['nickname' => 'Grace']);

        $threads = $this->documents(ForumThread::class);
        $this->assertSame('Grace', $threads[$mine[0]->id]['author']);
        $this->assertSame('Grace', $threads[$mine[1]->id]['author']);
        $this->assertSame('Alan', $threads[$theirs->id]['author']);
        $this->assertArrayNotHasKey($unpublished->id, $threads);
        $this->assertSame('Grace', $this->documents(Blog::class)[$blog->id]['author']);
        $this->assertSame('Alan', $this->documents(Blog::class)[$theirBlog->id]['author']);
    }

    #[Test]
    public function the_documents_of_others_are_not_sent_again_when_a_member_changes_their_nickname(): void
    {
        $member = User::factory()->create();
        $board = $this->board();
        $this->thread($board, $member);
        $this->thread($board, User::factory()->create());
        Blog::factory()->published()->create(['user_id' => User::factory()->create()->id]);

        $updates = $this->engine->updates;
        $member->update(['nickname' => 'Grace']);

        // Their one thread only.
        $this->assertSame($updates + 1, $this->engine->updates);
    }

    #[Test]
    public function a_member_changes_their_nickname_on_their_profile_and_the_index_follows(): void
    {
        $member = User::factory()->create(['nickname' => 'Ada']);
        $thread = $this->thread($this->board(), $member);

        $this->actingAs($member)
            ->patch(route('profile.update'), ['nickname' => 'Grace Hopper', 'email' => $member->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Grace Hopper', $member->fresh()->nickname);
        $this->assertSame('Grace Hopper', $this->documents(ForumThread::class)[$thread->id]['author']);
    }

    #[Test]
    public function something_else_about_a_member_that_changes_sends_nothing(): void
    {
        $member = User::factory()->create(['nickname' => 'Ada']);
        $this->thread($this->board(), $member);
        Queue::fake();

        $member->update(['email' => 'ada@example.org']);
        $member->update(['nickname' => 'Ada']);
        $member->touch();

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->engine->updates);
    }

    #[Test]
    public function a_member_with_nothing_in_the_index_sends_nothing_when_they_change_their_nickname(): void
    {
        $member = User::factory()->create();
        $this->thread($this->board(), User::factory()->create());
        Queue::fake();
        config(['scout.queue' => true]);

        $member->update(['nickname' => 'Grace']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_products_of_a_renamed_brand_are_sent_again_with_the_new_name(): void
    {
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $other = Brand::query()->create(['name' => 'Initech', 'slug' => 'initech']);

        $mine = [$this->product($brand), $this->product($brand)];
        $theirs = $this->product($other);
        $inactive = $this->product($brand, active: false);
        $without = $this->product(null);

        $this->assertSame('Acme', $this->documents(Product::class)[$mine[0]->id]['brand']);

        $updates = $this->engine->updates;
        $brand->update(['name' => 'Acme Industries']);

        // The two that are listed and are its own: not the other brand's product, the one without a brand, or the one that is not for sale.
        $this->assertSame($updates + 2, $this->engine->updates);

        $products = $this->documents(Product::class);
        $this->assertSame('Acme Industries', $products[$mine[0]->id]['brand']);
        $this->assertSame('Acme Industries', $products[$mine[1]->id]['brand']);
        $this->assertSame('Initech', $products[$theirs->id]['brand']);
        $this->assertArrayNotHasKey($inactive->id, $products);
        $this->assertArrayNotHasKey('brand', $products[$without->id]);
    }

    #[Test]
    public function a_brand_changed_in_another_way_than_its_name_sends_nothing(): void
    {
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->product($brand);
        $updates = $this->engine->updates;

        $brand->update(['description' => 'Everything for the coyote', 'slug' => 'acme-corp']);

        $this->assertSame($updates, $this->engine->updates);
    }

    #[Test]
    public function a_brand_renamed_in_the_shop_admin_is_followed_by_the_index(): void
    {
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $product = $this->product($brand);

        $this->actingAs($this->admin())
            ->put(route('acp.commerce.taxonomy.update', ['type' => 'brands', 'id' => $brand->id]), ['name' => 'Acme Industries', 'slug' => 'acme'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Acme Industries', $this->documents(Product::class)[$product->id]['brand']);
    }

    #[Test]
    public function the_products_of_a_deleted_brand_are_sent_again_without_its_name(): void
    {
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $other = Brand::query()->create(['name' => 'Initech', 'slug' => 'initech']);

        $mine = $this->product($brand);
        $theirs = $this->product($other);

        $this->assertSame('Acme', $this->documents(Product::class)[$mine->id]['brand']);

        $updates = $this->engine->updates;
        $brand->delete();

        // Its own product only, not the other brand's.
        $this->assertSame($updates + 1, $this->engine->updates);
        $this->assertNull($mine->fresh()->brand_id);
        $products = $this->documents(Product::class);
        $this->assertArrayNotHasKey('brand', $products[$mine->id]);
        $this->assertSame('Initech', $products[$theirs->id]['brand']);
    }

    #[Test]
    public function a_brand_deletion_that_is_called_off_sends_nothing(): void
    {
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $product = $this->product($brand);
        $updates = $this->engine->updates;

        Brand::deleting(fn () => false);

        $this->assertFalse($brand->delete());

        $this->assertSame($updates, $this->engine->updates);
        $this->assertSame('Acme', $this->documents(Product::class)[$product->id]['brand']);
    }

    #[Test]
    public function the_questions_of_a_renamed_faq_category_are_sent_again_with_the_new_name(): void
    {
        $category = FaqCategory::query()->create(['name' => 'Billing', 'slug' => 'billing', 'order' => 1]);
        $other = FaqCategory::query()->create(['name' => 'Shipping', 'slug' => 'shipping', 'order' => 2]);

        $mine = [$this->faq($category), $this->faq($category)];
        $theirs = $this->faq($other);
        $draft = $this->faq($category, published: false);

        $this->assertSame('Billing', $this->documents(Faq::class)[$mine[0]->id]['category']);

        $updates = $this->engine->updates;
        $category->update(['name' => 'Payments']);

        // The two published questions of the category: not the other category's, or the one that is not published.
        $this->assertSame($updates + 2, $this->engine->updates);

        $faqs = $this->documents(Faq::class);
        $this->assertSame('Payments', $faqs[$mine[0]->id]['category']);
        $this->assertSame('Payments', $faqs[$mine[1]->id]['category']);
        $this->assertSame('Shipping', $faqs[$theirs->id]['category']);
        $this->assertArrayNotHasKey($draft->id, $faqs);
    }

    #[Test]
    public function a_faq_category_renamed_in_the_admin_is_followed_by_the_index(): void
    {
        $category = FaqCategory::query()->create(['name' => 'Billing', 'slug' => 'billing', 'order' => 1]);
        $faq = $this->faq($category);

        $this->actingAs($this->admin())
            ->put(route('acp.support.faq-categories.update', $category), ['name' => 'Payments', 'slug' => 'billing', 'order' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame('Payments', $this->documents(Faq::class)[$faq->id]['category']);
    }

    #[Test]
    public function a_faq_category_changed_in_another_way_than_its_name_sends_nothing(): void
    {
        $category = FaqCategory::query()->create(['name' => 'Billing', 'slug' => 'billing', 'order' => 1]);
        $this->faq($category);
        $updates = $this->engine->updates;

        $category->update(['order' => 5, 'description' => 'Money matters']);

        $this->assertSame($updates, $this->engine->updates);
    }

    #[Test]
    public function with_the_queue_on_the_documents_are_queued_a_hundred_to_a_job_and_sent_by_the_workers(): void
    {
        $member = User::factory()->create(['nickname' => 'Ada']);
        $board = $this->board();
        $rows = [];

        foreach (range(1, 250) as $n) {
            $rows[] = [
                'forum_board_id' => $board->id, 'user_id' => $member->id, 'title' => "Thread {$n}", 'slug' => "thread-{$n}",
                'is_locked' => false, 'is_pinned' => false, 'is_published' => true, 'views' => 0, 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 125) as $chunk) {
            DB::table('forum_threads')->insert($chunk);
        }

        $this->assertSame(100, RefreshSearchDocuments::CHUNK);

        config(['scout.queue' => true]);
        Queue::fake();

        $member->update(['nickname' => 'Grace']);

        Queue::assertPushed(RefreshSearchDocuments::class, 3);
        // Nothing is sent while the request is handled.
        $this->assertSame(0, $this->engine->updates);

        $jobs = Queue::pushed(RefreshSearchDocuments::class)->values();
        $this->assertSame([100, 100, 50], $jobs->map(fn (RefreshSearchDocuments $job) => count($job->keys))->all());
        $this->assertTrue($jobs->every(fn (RefreshSearchDocuments $job) => $job->afterCommit === true));
        $this->assertSame(250, $jobs->flatMap(fn (RefreshSearchDocuments $job) => $job->keys)->unique()->count());

        // A worker gets each one as text, and hands the documents to Scout's own job.
        foreach ($jobs as $job) {
            unserialize(serialize($job))->handle();
        }

        Queue::assertPushed(MakeSearchable::class, 3);
        $this->assertSame(0, $this->engine->updates);

        foreach (Queue::pushed(MakeSearchable::class) as $send) {
            unserialize(serialize($send))->handle();
        }

        $this->assertSame(250, $this->engine->updates);
        $this->assertSame(['Grace'], collect($this->documents(ForumThread::class))->pluck('author')->unique()->values()->all());
    }

    #[Test]
    public function the_keys_are_queued_once_each(): void
    {
        Queue::fake();

        RefreshSearchDocuments::dispatchInChunks(ForumThread::class, [3, 1, 3, 2, 1]);

        Queue::assertPushed(RefreshSearchDocuments::class, 1);
        $this->assertSame([3, 1, 2], Queue::pushed(RefreshSearchDocuments::class)->first()->keys);

        RefreshSearchDocuments::dispatchInChunks(ForumThread::class, []);

        Queue::assertPushed(RefreshSearchDocuments::class, 1);
    }

    #[Test]
    public function a_job_leaves_out_the_rows_that_are_gone_and_those_that_are_no_longer_listed(): void
    {
        $member = User::factory()->create();
        $board = $this->board();
        $listed = $this->thread($board, $member);
        $withdrawn = $this->thread($board, $member);
        $deleted = $this->thread($board, $member);

        $withdrawn->update(['is_published' => false]);
        $deleted->delete();
        $updates = $this->engine->updates;

        (new RefreshSearchDocuments(ForumThread::class, [$listed->id, $withdrawn->id, $deleted->id, 999_999]))->handle();

        $this->assertSame($updates + 1, $this->engine->updates);
        $this->assertSame([$listed->id], array_keys($this->documents(ForumThread::class)));
    }

    #[Test]
    public function a_queue_that_will_not_take_the_job_is_reported_and_does_not_undo_the_rename(): void
    {
        Exceptions::fake();

        $member = User::factory()->create(['nickname' => 'Ada']);
        $this->thread($this->board(), $member);
        config(['queue.default' => 'unreachable', 'queue.connections.unreachable' => ['driver' => 'no-such-driver']]);

        $member->update(['nickname' => 'Grace']);

        $this->assertSame('Grace', $member->fresh()->nickname);
        Exceptions::assertReportedCount(1);
    }

    #[Test]
    public function with_the_database_search_no_query_looks_for_documents_and_nothing_is_queued(): void
    {
        config(['scout.driver' => 'null']);

        $member = User::factory()->create();
        $this->thread($this->board(), $member);
        $brand = Brand::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->product($brand);
        $category = FaqCategory::query()->create(['name' => 'Billing', 'slug' => 'billing', 'order' => 1]);
        $this->faq($category);
        Queue::fake();

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = str_replace(['"', '`'], '', $query->sql);
        });

        $member->update(['nickname' => 'Grace']);
        $brand->update(['name' => 'Acme Industries']);
        $category->update(['name' => 'Payments']);
        $brand->delete();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        Queue::assertNothingPushed();

        foreach ($sql as $statement) {
            $this->assertStringStartsNotWith('select id from forum_threads', $statement);
            $this->assertStringStartsNotWith('select id from blogs', $statement);
            $this->assertStringStartsNotWith('select id from products', $statement);
            $this->assertStringStartsNotWith('select id from faqs', $statement);
        }
    }

    #[Test]
    public function nothing_is_queued_for_a_refresh_with_no_rows_and_a_refresh_of_nothing_is_nothing(): void
    {
        Queue::fake();

        app(CascadedDocuments::class)->refresh([]);
        app(CascadedDocuments::class)->refresh([ForumThread::class => DB::table('forum_threads')->where('user_id', 0)]);

        Queue::assertNothingPushed();
    }
}
