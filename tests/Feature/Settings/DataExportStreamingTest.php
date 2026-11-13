<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DataExport;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ModerationAction;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Notifications\SplitDelivery;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ReadsDataExport;
use Tests\TestCase;

/**
 * A member's data export is made without holding what the member has written: a list is read a page of rows at a time and written to the file as it
 * goes. A member with tens of thousands of posts used to run the worker out of memory (50,000 did not fit in 256 MB), which PHP does not let a job
 * catch, so the export stayed `processing`, and a member with an export `processing` is told to wait for it and cannot ask for another.
 */
class DataExportStreamingTest extends TestCase
{
    use ReadsDataExport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private function blog(User $user): Blog
    {
        return Blog::factory()->for($user)->create();
    }

    private function comment(Blog $blog, User $user, string $body, string $at): BlogComment
    {
        return BlogComment::factory()->for($blog)->for($user)->create(['body' => $body, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function thread(User $user): ForumThread
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'description' => 'News', 'position' => 1]);

        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $user->id,
            'title' => 'A thread',
            'slug' => 'a-thread',
            'excerpt' => 'Thread',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    /**
     * The job with pages of two rows, so that a handful of rows is several pages.
     *
     * @return Closure(int): GenerateUserDataExport
     */
    private function pagesOfTwo(): Closure
    {
        return fn (int $exportId): GenerateUserDataExport => new class($exportId) extends GenerateUserDataExport
        {
            protected const PAGE = 2;
        };
    }

    // --- The file --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function export_json_is_laid_out_as_json_encode_lays_it_out(): void
    {
        $user = User::factory()->create(['social_links' => ['github' => 'https://github.com/a/b?x=1&y=2']]);
        $blog = $this->blog($user);
        $this->comment($blog, $user, "A \"quoted\" word, a back\\slash, a / slash,\nand a line break, é 日本語 😀", '2026-10-01 10:00:00');
        $this->comment($blog, $user, '=1+1', '2026-10-02 10:00:00');
        SupportTicket::factory()->for($user)->create(['subject' => 'Where is my order?']);
        $product = Product::factory()->create(['name' => 'Widget']);
        $user->wishlistItems()->create(['product_id' => $product->id]);
        ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 2, 'title' => null, 'body' => 'Not what I hoped for.']);
        $user->createToken('CI', ['profile:read', 'polls:vote']);
        UsageEvent::create(['user_id' => $user->id, 'meter' => 'api.requests', 'quantity' => 3, 'occurred_at' => '2026-10-10 08:00:00']);
        ModerationAction::factory()->create(['user_id' => $user->id, 'type' => ModerationAction::WARNING, 'reason' => 'Please stop that']);

        $raw = $this->dataExportOf($user)['json'];

        // What was read from the file, written out again the way the export used to write it, is the file: same indent, same commas, same escapes.
        $this->assertSame(json_encode(json_decode($raw, true, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $raw);
        $this->assertSame(
            ['user', 'profile', 'blog_comments', 'forum_posts', 'support_tickets', 'wishlist', 'product_reviews', 'api_keys', 'usage', 'moderation'],
            array_keys(json_decode($raw, true)),
        );
    }

    #[Test]
    public function a_member_with_nothing_gets_empty_lists_and_a_header_row(): void
    {
        $user = User::factory()->create();

        $files = $this->dataExportOf($user);
        $json = json_decode($files['json'], true, flags: JSON_THROW_ON_ERROR);

        foreach (['blog_comments', 'forum_posts', 'support_tickets', 'wishlist', 'product_reviews', 'api_keys', 'usage', 'moderation'] as $list) {
            $this->assertSame([], $json[$list], $list);
        }

        $this->assertStringContainsString("\"forum_posts\": [],\n", $files['json']);
        $this->assertSame("resource_type,resource_id,summary,created_at,updated_at\n", $files['csv']);
        $this->assertSame($user->email, $json['user']['email']);
    }

    // --- Order and pages -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function rows_come_out_oldest_first_whatever_their_ids_are_and_a_tie_in_the_order_of_the_ids(): void
    {
        $user = User::factory()->create();
        $blog = $this->blog($user);
        $newest = $this->comment($blog, $user, 'newest', '2026-10-03 10:00:00');
        $oldest = $this->comment($blog, $user, 'oldest', '2026-10-01 10:00:00');
        $tieA = $this->comment($blog, $user, 'tie a', '2026-10-02 10:00:00');
        $tieB = $this->comment($blog, $user, 'tie b', '2026-10-02 10:00:00');

        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (preg_match('/^select [`"]id[`"] from [`"]blog_comments[`"]/', $query->sql) === 1) {
                $reads[] = $query->sql;
            }
        });

        $files = $this->dataExportOf($user);

        // The ids are read once, in the order of the file: by time, and two of the same time by id (SQLite alone would give them by id anyway).
        $this->assertCount(1, $reads);
        $this->assertMatchesRegularExpression('/order by [`"]created_at[`"] asc, [`"]id[`"] asc$/', $reads[0]);

        $expected = [$oldest->id, $tieA->id, $tieB->id, $newest->id];
        $this->assertSame($expected, array_column(json_decode($files['json'], true)['blog_comments'], 'id'));

        $csvIds = array_map(fn (string $row): string => explode(',', $row)[1], array_slice(explode("\n", trim($files['csv'])), 1));
        $this->assertSame(array_map('strval', $expected), $csvIds);
    }

    #[Test]
    public function more_rows_than_a_page_all_come_out_once_and_in_order(): void
    {
        $user = User::factory()->create();
        $blog = $this->blog($user);
        $thread = $this->thread($user);

        $ids = [];
        foreach ([7, 3, 5, 1, 6, 2, 4] as $day) { // seven comments made out of order, in pages of two
            $ids[$day] = $this->comment($blog, $user, "comment $day", "2026-10-0$day 10:00:00")->id;
        }
        ksort($ids);

        foreach ([3, 1, 2] as $day) {
            ForumPost::unguarded(fn () => ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $user->id, 'body' => "post $day", 'created_at' => "2026-10-0$day 10:00:00", 'updated_at' => "2026-10-0$day 10:00:00"]));
        }

        $files = $this->dataExportOf($user, $this->pagesOfTwo());

        $json = json_decode($files['json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(array_values($ids), array_column($json['blog_comments'], 'id'));
        $this->assertSame(['post 1', 'post 2', 'post 3'], array_column($json['forum_posts'], 'body'));
        $this->assertSame(7 + 3 + 1, substr_count($files['csv'], "\n"), 'a header and a row for each of the ten');
    }

    #[Test]
    public function the_bodies_are_read_a_page_of_rows_at_a_time_and_not_all_at_once(): void
    {
        $user = User::factory()->create();
        $blog = $this->blog($user);

        foreach (range(1, 7) as $day) {
            $this->comment($blog, $user, "comment $day", "2026-10-0$day 10:00:00");
        }

        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'from "blog_comments"') || str_contains($query->sql, 'from `blog_comments`')) {
                $reads[] = $query;
            }
        });

        $this->dataExportOf($user, $this->pagesOfTwo());

        $withBodies = array_values(array_filter($reads, fn ($query) => str_contains($query->sql, 'body')));

        $this->assertCount(4, $withBodies, 'seven rows in pages of two are four reads of bodies');
        $this->assertCount(5, $reads, 'the four pages, and the one read of the ids before them');

        foreach ($withBodies as $query) {
            $this->assertLessThanOrEqual(3, count($query->bindings), 'the member and at most two ids');
        }
    }

    #[Test]
    public function the_products_of_a_page_are_read_together_and_not_one_for_each_row(): void
    {
        $user = User::factory()->create();

        foreach (['One', 'Two', 'Three'] as $name) {
            $product = Product::factory()->create(['name' => $name]);
            $user->wishlistItems()->create(['product_id' => $product->id]);
            ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 4, 'title' => "About $name", 'body' => 'Fine.']);
        }

        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (preg_match('/from [`"]products[`"]/', $query->sql) === 1) {
                $reads++;
            }
        });

        $json = json_decode($this->dataExportOf($user)['json'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['One', 'Two', 'Three'], array_column($json['wishlist'], 'product'));
        $this->assertSame(['One', 'Two', 'Three'], array_column($json['product_reviews'], 'product'));
        $this->assertSame(2, $reads, 'one read of products for the wishlist and one for the reviews');
    }

    #[Test]
    public function a_row_deleted_while_the_export_is_being_made_is_left_out_and_nothing_else_is(): void
    {
        $user = User::factory()->create();
        $blog = $this->blog($user);

        $made = [];
        foreach (range(1, 5) as $day) {
            $made[$day] = $this->comment($blog, $user, "comment $day", "2026-10-0$day 10:00:00");
        }

        // The ids are read first; then, as the first page of bodies is read, the third comment is deleted by somebody else.
        $deleted = false;
        DB::listen(function ($query) use (&$deleted, $made) {
            if (! $deleted && str_contains($query->sql, 'body') && str_contains($query->sql, 'blog_comments')) {
                $deleted = true;
                DB::table('blog_comments')->where('id', $made[3]->id)->delete();
            }
        });

        $files = $this->dataExportOf($user, $this->pagesOfTwo());

        $this->assertTrue($deleted);
        // Page one (comments 1 and 2) was read before the delete; page two would have had the third and the fourth.
        $this->assertSame([$made[1]->id, $made[2]->id, $made[4]->id, $made[5]->id], array_column(json_decode($files['json'], true)['blog_comments'], 'id'));
    }

    // --- When it goes wrong ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_export_the_worker_gave_up_on_is_failed_and_the_member_can_ask_again(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $export = DataExport::factory()->for($user)->create(['status' => DataExport::STATUS_PROCESSING]);
        $someoneElses = DataExport::factory()->for(User::factory()->create())->create(['status' => DataExport::STATUS_PROCESSING]);

        // What is left behind when a worker is killed for running out of memory: the export is `processing` and nothing says it is over.
        $this->actingAs($user)->post(route('privacy.exports.store'))->assertSessionHasErrors('export');

        (new GenerateUserDataExport($export->id))->failed(new RuntimeException('Allowed memory size exhausted'));

        $export->refresh();
        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(GenerateUserDataExport::FAILURE_REASON, $export->failure_reason);
        $this->assertSame(DataExport::STATUS_PROCESSING, $someoneElses->fresh()->status, 'only the export of that job is failed');

        $this->flushSession();
        $this->actingAs($user)->post(route('privacy.exports.store'))->assertSessionHasNoErrors();
        $this->assertSame(2, $user->dataExports()->count());
        $this->assertSame(1, $user->dataExports()->where('status', DataExport::STATUS_PENDING)->count());
    }

    #[Test]
    public function a_worker_that_was_killed_gets_its_export_failed_when_the_job_comes_round_again(): void
    {
        $user = User::factory()->create();
        $export = DataExport::factory()->for($user)->create(['status' => DataExport::STATUS_PROCESSING]);

        config(['queue.default' => 'database']);
        GenerateUserDataExport::dispatch($export->id);

        // The first worker took the job, counted an attempt, and died with it. After `retry_after` the job is free to be taken again.
        $this->assertSame(1, DB::table('jobs')->count());
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subMinutes(10)->getTimestamp()]);

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(GenerateUserDataExport::FAILURE_REASON, $export->failure_reason);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function a_job_that_never_got_to_start_fails_its_export_too(): void
    {
        $export = DataExport::factory()->for(User::factory()->create())->create(['status' => DataExport::STATUS_PENDING]);

        // Given up on before `handle()` ran: out of attempts, or the queue's table was cleared of its reservation.
        (new GenerateUserDataExport($export->id))->failed(null);

        $this->assertSame(DataExport::STATUS_FAILED, $export->fresh()->status);
    }

    #[Test]
    public function an_export_that_failed_and_is_run_again_has_no_reason_left_over(): void
    {
        $user = User::factory()->create();
        Storage::fake('local');
        Notification::fake();

        $export = DataExport::factory()->for($user)->create(['status' => DataExport::STATUS_FAILED, 'failure_reason' => GenerateUserDataExport::FAILURE_REASON]);

        (new GenerateUserDataExport($export->id))->handle();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertNull($export->failure_reason);
    }

    #[Test]
    public function an_export_that_has_finished_is_not_failed_afterwards(): void
    {
        $user = User::factory()->create();
        $export = DataExport::factory()->for($user)->create(['status' => DataExport::STATUS_COMPLETED, 'file_path' => 'exports/user-1.zip', 'completed_at' => now()]);

        // A worker taken for dead may have got to the end after all.
        (new GenerateUserDataExport($export->id))->failed(null);

        $export->refresh();
        $this->assertSame(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertNull($export->failure_reason);
    }

    #[Test]
    public function the_member_is_told_a_sentence_and_not_what_the_exception_said(): void
    {
        $user = User::factory()->create();
        $export = DataExport::factory()->for($user)->create();

        // The message of a database error has the query and its values in it, and the member reads `failure_reason` on their privacy page.
        $this->mock(SplitDelivery::class)->shouldReceive('send')->andThrow(new RuntimeException('SQLSTATE[HY000]: select * from users where email = secret@example.com'));

        try {
            (new GenerateUserDataExport($export->id))->handle();
            $this->fail('The exception was expected to go on to the queue.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SQLSTATE', $exception->getMessage(), 'the queue still gets the real exception');
        }

        $export->refresh();
        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(GenerateUserDataExport::FAILURE_REASON, $export->failure_reason);
        $this->assertStringNotContainsString('SQLSTATE', (string) $export->failure_reason);
    }
}
