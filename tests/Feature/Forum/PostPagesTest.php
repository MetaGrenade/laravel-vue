<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\PostPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which page of its thread a post is on, for a link that has to open the page that holds it (docs/query-budgets.md).
 */
class PostPagesTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Lounge', 'slug' => 'lounge', 'description' => 'Chat', 'position' => 1]);
        $this->author = User::factory()->create();
    }

    private function thread(): ForumThread
    {
        $title = 'Thread '.Str::random(6);

        return ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $this->author->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $this->author->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function reply(ForumThread $thread, int $minutes, array $over = []): ForumPost
    {
        $post = new ForumPost(['forum_thread_id' => $thread->id, 'user_id' => $this->author->id, 'body' => 'A reply.']);
        $post->forceFill(['created_at' => now()->startOfHour()->addMinutes($minutes), 'updated_at' => now(), ...$over])->save();

        return $post;
    }

    /**
     * @return list<ForumPost> Oldest first, one a minute.
     */
    private function posts(ForumThread $thread, int $count): array
    {
        return array_map(fn (int $minute) => $this->reply($thread, $minute), range(1, $count));
    }

    #[Test]
    public function ten_posts_make_a_page(): void
    {
        $posts = $this->posts($this->thread(), 25);
        $pages = PostPages::of($posts);

        $this->assertSame([1, 1, 2, 2, 3], array_map($pages->page(...), [$posts[0], $posts[9], $posts[10], $posts[19], $posts[24]]));
    }

    #[Test]
    public function the_first_page_is_the_threads_address_and_a_later_one_names_its_page(): void
    {
        $thread = $this->thread();
        $posts = $this->posts($thread, 11);
        $pages = PostPages::of($posts);

        $this->assertSame(route('forum.threads.show', [$this->board, $thread]).'#post-'.$posts[3]->id, $pages->address($posts[3], $this->board, $thread));
        $this->assertSame(route('forum.threads.show', [$this->board, $thread, 'page' => 2]).'#post-'.$posts[10]->id, $pages->address($posts[10], $this->board, $thread));
        $this->assertStringContainsString('/forum/lounge/'.$thread->slug.'?page=2#post-', $pages->address($posts[10], 'lounge', $thread->slug), 'slugs do as well as models');
    }

    #[Test]
    public function held_and_deleted_replies_are_not_counted_because_the_thread_page_does_not_show_them(): void
    {
        $thread = $this->thread();
        $this->reply($thread, 1, ['held_at' => now()]);
        $removed = $this->reply($thread, 2);
        $removed->delete();
        $posts = array_map(fn (int $minute) => $this->reply($thread, $minute), range(3, 13));

        $pages = PostPages::of($posts);

        $this->assertSame(1, $pages->page($posts[9]), 'the tenth one that is shown');
        $this->assertSame(2, $pages->page($posts[10]), 'the eleventh');
    }

    #[Test]
    public function posts_made_in_the_same_second_are_ordered_by_id(): void
    {
        $thread = $this->thread();
        $posts = array_map(fn () => $this->reply($thread, 5), range(1, 12));
        $pages = PostPages::of($posts);

        $this->assertSame(1, $pages->page($posts[9]));
        $this->assertSame(2, $pages->page($posts[10]));
        $this->assertSame(2, $pages->page($posts[11]));
    }

    #[Test]
    public function another_threads_posts_are_not_counted(): void
    {
        $other = $this->thread();
        $this->posts($other, 30);
        $thread = $this->thread();
        $post = $this->reply($thread, 40);

        $this->assertSame(1, PostPages::of([$post])->page($post));
    }

    #[Test]
    public function posts_of_many_threads_are_placed_with_one_query(): void
    {
        $posts = [];

        foreach (range(1, 5) as $unused) {
            $thread = $this->thread();
            $posts[] = $this->posts($thread, 12)[11];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pages = PostPages::of($posts);
        $asked = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $asked);
        $this->assertSame([2, 2, 2, 2, 2], array_map($pages->page(...), $posts));

        DB::flushQueryLog();
        DB::enableQueryLog();
        array_map($pages->page(...), $posts);
        $this->assertSame([], DB::getQueryLog(), 'and asking for them again costs nothing');
        DB::disableQueryLog();
    }

    #[Test]
    public function a_post_that_was_not_asked_for_up_front_is_still_placed(): void
    {
        $posts = $this->posts($this->thread(), 12);
        $pages = PostPages::of([$posts[0]]);

        $this->assertSame(2, $pages->page($posts[11]));
    }

    #[Test]
    public function nothing_to_place_asks_nothing(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        PostPages::of([]);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }
}
