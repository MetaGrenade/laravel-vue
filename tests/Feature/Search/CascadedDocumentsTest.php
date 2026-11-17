<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Jobs\RemoveFromSearch;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deleting a board, a category or an account takes threads, FAQs and blog posts with it by `ON DELETE CASCADE`, which runs no model event,
 * so Scout never heard of them and their documents stayed in the search index (docs/search-index.md).
 *
 * Scout's collection engine reads the database, so it cannot show a document that is left over: this engine keeps what it was given.
 */
class CascadedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private KeepingSearchEngine $engine;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Not `$this->engine` inside the closure: Laravel binds the closure it is given to its own manager.
        $this->engine = $engine = new KeepingSearchEngine;
        $this->app->make(EngineManager::class)->extend('keeping', fn () => $engine);
        config(['scout.driver' => 'keeping', 'scout.queue' => false]);
    }

    private function category(): ForumCategory
    {
        return ForumCategory::query()->create(['title' => 'Category '.++$this->sequence, 'slug' => 'category-'.$this->sequence, 'description' => 'x', 'position' => $this->sequence]);
    }

    private function board(ForumCategory $category): ForumBoard
    {
        return ForumBoard::query()->create(['forum_category_id' => $category->id, 'title' => 'Board '.++$this->sequence, 'slug' => 'board-'.$this->sequence, 'description' => 'x', 'position' => $this->sequence]);
    }

    private function thread(ForumBoard $board, ?User $author = null, bool $published = true): ForumThread
    {
        $author ??= User::factory()->create();
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

    private function reply(ForumThread $thread, User $author, string $body, bool $held = false): ForumPost
    {
        $reply = new ForumPost;
        $reply->forceFill(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => $body, 'held_at' => $held ? now() : null])->save();

        return $reply;
    }

    private function faq(FaqCategory $category): Faq
    {
        return Faq::query()->create(['faq_category_id' => $category->id, 'question' => 'Question '.++$this->sequence, 'answer' => 'An answer', 'published' => true, 'order' => $this->sequence]);
    }

    /**
     * The keys of the documents the index holds for a model.
     *
     * @param  class-string  $model
     * @return list<int>
     */
    private function indexed(string $model): array
    {
        $keys = array_keys($this->engine->documents[$model] ?? []);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<ForumThread>|list<Faq>|list<Blog>  $models
     * @return list<int>
     */
    private function keys(array $models): array
    {
        $keys = array_map(fn ($model) => (int) $model->getKey(), $models);
        sort($keys);

        return $keys;
    }

    #[Test]
    public function the_threads_of_a_deleted_board_leave_the_index_and_those_of_the_next_board_stay(): void
    {
        $category = $this->category();
        $gone = $this->board($category);
        $kept = $this->board($category);

        $mine = [$this->thread($gone), $this->thread($gone), $this->thread($gone, published: false)];
        $theirs = [$this->thread($kept), $this->thread($kept)];

        $this->assertSame($this->keys([$mine[0], $mine[1], ...$theirs]), $this->indexed(ForumThread::class)); // an unpublished thread is not indexed

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->delete(route('acp.forums.boards.destroy', $gone))->assertRedirect(route('acp.forums.index'));

        $this->assertDatabaseMissing('forum_boards', ['id' => $gone->id]);
        $this->assertSame($this->keys($theirs), $this->indexed(ForumThread::class));
    }

    #[Test]
    public function the_threads_of_every_board_of_a_deleted_category_leave_the_index_and_those_of_another_category_stay(): void
    {
        $general = $this->category();
        $shop = $this->category();

        $first = $this->board($general);
        $second = $this->board($general);
        $third = $this->board($shop);

        // Made in this order, so that no thread has the number of the board it is in: a thread found by its own number among the boards of
        // the category would be the wrong one.
        $theirs = [$this->thread($third)];
        $this->thread($first);
        $this->thread($second);

        $this->assertCount(3, $this->indexed(ForumThread::class));

        $general->delete();

        $this->assertSame($this->keys($theirs), $this->indexed(ForumThread::class));
    }

    #[Test]
    public function the_questions_of_a_deleted_faq_category_leave_the_index_and_those_of_another_category_stay(): void
    {
        $gone = FaqCategory::query()->create(['name' => 'Gone', 'slug' => 'gone', 'order' => 1]);
        $kept = FaqCategory::query()->create(['name' => 'Kept', 'slug' => 'kept', 'order' => 2]);

        $this->faq($gone);
        $this->faq($gone);
        $theirs = [$this->faq($kept)];

        $this->assertCount(3, $this->indexed(Faq::class));

        $gone->delete();

        $this->assertDatabaseCount('faqs', 1);
        $this->assertSame($this->keys($theirs), $this->indexed(Faq::class));
    }

    #[Test]
    public function the_threads_and_blog_posts_of_a_deleted_member_leave_the_index_and_those_of_others_stay(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $board = $this->board($this->category());

        $this->thread($board, $member);
        $this->thread($board, $member);
        $theirs = [$this->thread($board, $other)];

        Blog::factory()->published()->create(['user_id' => $member->id, 'title' => 'Mine']);
        $blogs = [Blog::factory()->published()->create(['user_id' => $other->id, 'title' => 'Theirs'])];

        $this->assertCount(3, $this->indexed(ForumThread::class));
        $this->assertCount(2, $this->indexed(Blog::class));

        $member->delete();

        $this->assertSame($this->keys($theirs), $this->indexed(ForumThread::class));
        $this->assertSame($this->keys($blogs), $this->indexed(Blog::class));
    }

    #[Test]
    public function a_thread_of_somebody_else_is_made_again_without_the_replies_of_a_deleted_member(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $board = $this->board($this->category());

        $thread = $this->thread($board, $other);
        $this->reply($thread, $other, '<p>an answer that stays</p>');
        $this->reply($thread, $member, '<p>secretword that goes</p>');
        $this->reply($thread, $member, '<p>and a second one</p>');

        $this->assertStringContainsString('secretword', $this->engine->documents[ForumThread::class][$thread->id]['replies']);

        $member->delete();

        $this->assertSame([$thread->id], $this->indexed(ForumThread::class));
        $document = $this->engine->documents[ForumThread::class][$thread->id];
        $this->assertStringContainsString('an answer that stays', $document['replies']);
        $this->assertStringNotContainsString('secretword', $document['replies']);
        $this->assertStringNotContainsString('second one', $document['replies']);
    }

    #[Test]
    public function a_thread_is_made_again_once_however_many_replies_a_deleted_member_had_in_it(): void
    {
        $member = User::factory()->create();
        $board = $this->board($this->category());
        $thread = $this->thread($board, User::factory()->create());

        foreach (range(1, 4) as $n) {
            $this->reply($thread, $member, "<p>reply {$n}</p>");
        }

        $updates = $this->engine->updates;
        $member->delete();

        $this->assertSame($updates + 1, $this->engine->updates);
    }

    #[Test]
    public function a_reply_that_was_never_in_a_document_does_not_ask_for_its_thread_to_be_made_again(): void
    {
        $member = User::factory()->create();
        $board = $this->board($this->category());
        $thread = $this->thread($board, User::factory()->create());

        $this->reply($thread, $member, '<p>held for review</p>', held: true);

        $updates = $this->engine->updates;
        $member->delete();

        $this->assertSame($updates, $this->engine->updates);
        $this->assertSame([$thread->id], $this->indexed(ForumThread::class));
    }

    #[Test]
    public function a_member_with_nothing_in_the_index_asks_it_for_nothing(): void
    {
        $member = User::factory()->create();
        $board = $this->board($this->category());
        $thread = $this->thread($board, User::factory()->create());
        $this->reply($thread, User::factory()->create(), '<p>nothing to do with them</p>');

        $updates = $this->engine->updates;
        $deletes = $this->engine->deletes;

        $member->delete();

        $this->assertSame($updates, $this->engine->updates);
        $this->assertSame($deletes, $this->engine->deletes);
    }

    #[Test]
    public function nothing_is_asked_for_when_nothing_was_found(): void
    {
        app(CascadedDocuments::class)->after(null);
        app(CascadedDocuments::class)->after(new DocumentsLeaving);

        $this->assertSame(0, $this->engine->deletes);
        $this->assertSame(0, $this->engine->updates);
    }

    #[Test]
    public function documents_are_asked_for_in_batches_of_five_hundred(): void
    {
        $board = $this->board($this->category());
        $author = User::factory()->create();

        $rows = [];

        foreach (range(1, 1200) as $n) {
            $rows[] = [
                'forum_board_id' => $board->id, 'user_id' => $author->id, 'title' => "Thread {$n}", 'slug' => "thread-{$n}",
                'is_locked' => false, 'is_pinned' => false, 'is_published' => true, 'views' => 0, 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('forum_threads')->insert($chunk);
        }

        $keys = DB::table('forum_threads')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->engine->documents[ForumThread::class] = array_fill_keys($keys, ['title' => 'x']);

        $this->assertSame(500, CascadedDocuments::BATCH);

        $board->delete();

        $this->assertSame([500, 500, 200], $this->engine->batches);
        $this->assertSame([], $this->indexed(ForumThread::class));
    }

    #[Test]
    public function with_the_queue_on_each_batch_is_a_job_that_removes_by_key_alone(): void
    {
        $board = $this->board($this->category());
        $threads = [$this->thread($board), $this->thread($board), $this->thread($board)];

        $this->assertCount(3, $this->indexed(ForumThread::class));

        config(['scout.queue' => true]);
        Queue::fake();

        $board->delete();

        Queue::assertPushed(RemoveFromSearch::class, 1);
        // Nothing was sent while the request was handled.
        $this->assertCount(3, $this->indexed(ForumThread::class));

        $job = Queue::pushed(RemoveFromSearch::class)->first();
        $this->assertInstanceOf(RemoveFromSearch::class, $job);

        // A worker gets it as text, and the rows are gone by then: the job has to be able to do it from the keys.
        $restored = unserialize(serialize($job));
        $restored->handle();

        $this->assertSame([], $this->indexed(ForumThread::class));
        $this->assertSame($this->keys($threads), collect($this->engine->removed)->sort()->values()->all());
    }

    #[Test]
    public function an_index_that_would_not_answer_is_reported_and_does_not_undo_the_deletion(): void
    {
        Exceptions::fake();

        $board = $this->board($this->category());
        $this->thread($board);
        $this->engine->failWith = new RuntimeException('Meilisearch is down');

        $board->delete();

        $this->assertDatabaseMissing('forum_boards', ['id' => $board->id]);
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Meilisearch is down');
    }

    #[Test]
    public function a_batch_that_failed_does_not_stop_the_next_one(): void
    {
        Exceptions::fake();

        $board = $this->board($this->category());
        $author = User::factory()->create();
        $rows = [];

        foreach (range(1, 600) as $n) {
            $rows[] = ['forum_board_id' => $board->id, 'user_id' => $author->id, 'title' => "T{$n}", 'slug' => "t-{$n}", 'is_locked' => false, 'is_pinned' => false, 'is_published' => true, 'views' => 0, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('forum_threads')->insert($rows);
        $keys = DB::table('forum_threads')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->engine->documents[ForumThread::class] = array_fill_keys($keys, ['title' => 'x']);
        $this->engine->failWith = new RuntimeException('Meilisearch is down');
        $this->engine->failOnce = true;

        $board->delete();

        Exceptions::assertReportedCount(1);
        // The first 500 stayed (the engine refused them), and the last 100 went.
        $this->assertSame(array_slice($keys, 0, 500), $this->indexed(ForumThread::class));
    }

    #[Test]
    public function a_deletion_that_is_called_off_takes_nothing_out_of_the_index(): void
    {
        $board = $this->board($this->category());
        $threads = [$this->thread($board), $this->thread($board)];

        // Something that listens after the board's own hook and says no.
        ForumBoard::deleting(fn () => false);

        $this->assertFalse($board->delete());

        $this->assertDatabaseHas('forum_boards', ['id' => $board->id]);
        $this->assertSame($this->keys($threads), $this->indexed(ForumThread::class));
    }

    #[Test]
    public function with_the_database_search_no_query_looks_for_documents_to_forget(): void
    {
        config(['scout.driver' => 'null']);

        $member = User::factory()->create();
        $board = $this->board($this->category());
        $this->thread($board, $member);
        $faqCategory = FaqCategory::query()->create(['name' => 'Gone', 'slug' => 'gone', 'order' => 1]);
        $this->faq($faqCategory);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = str_replace(['"', '`'], '', $query->sql);
        });

        $board->delete();
        $faqCategory->delete();
        $member->delete();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);

        foreach ($sql as $statement) {
            $this->assertStringStartsNotWith('select id from forum_threads', $statement);
            $this->assertStringStartsNotWith('select id from blogs', $statement);
            $this->assertStringStartsNotWith('select id from faqs', $statement);
            $this->assertStringStartsNotWith('select distinct forum_thread_id', $statement);
        }
    }
}

/**
 * An index that keeps the documents it is given, and what it was asked to remove.
 */
class KeepingSearchEngine extends NullEngine
{
    /** @var array<class-string, array<int, array<string, mixed>>> */
    public array $documents = [];

    public int $updates = 0;

    public int $deletes = 0;

    /** @var list<int> The size of each removal that was asked for. */
    public array $batches = [];

    /** @var list<int> Every key it was asked to remove. */
    public array $removed = [];

    public ?RuntimeException $failWith = null;

    public bool $failOnce = false;

    public function update($models): void
    {
        foreach ($models as $model) {
            $this->updates++;
            $this->documents[$model::class][(int) $model->getScoutKey()] = $model->toSearchableArray();
        }
    }

    public function delete($models): void
    {
        $this->deletes += count($models);
        $this->batches[] = count($models);

        if ($this->failWith !== null) {
            $failure = $this->failWith;

            if ($this->failOnce) {
                $this->failWith = null;
            }

            throw $failure;
        }

        foreach ($models as $model) {
            $this->removed[] = (int) $model->getScoutKey();
            unset($this->documents[$model::class][(int) $model->getScoutKey()]);
        }
    }
}
