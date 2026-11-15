<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What is typed into a search box is letters, not a pattern. In a `LIKE`, `%` is "anything" and `_` is "any one character": a search for
 * `100%` found everything with `100` in it, a search for `_` found every row, and `a_b` found `axb`. Where the text was escaped (the
 * support desk, the forum), it was escaped with a backslash, which SQLite does not take as an escape character, so on SQLite a search for
 * `a_b` found nothing at all. `LikePattern` names the escape character in the statement, and every search below uses it.
 */
class SearchTermsAreLettersTest extends TestCase
{
    use RefreshDatabase;

    /** What is stored, and what is typed to find it. Each term finds exactly the titles listed, and no other. */
    private const TITLES = ['100% sure', '100 sure', 'a_b', 'axb', 'bang!', 'bang', 'a\\b', 'ab'];

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function terms(): array
    {
        return [
            'a percent is a percent' => ['100%', ['100% sure']],
            'a percent alone' => ['%', ['100% sure']],
            'an underscore is an underscore' => ['a_b', ['a_b']],
            'an underscore alone' => ['_', ['a_b']],
            'the escape character is a letter too' => ['bang!', ['bang!']],
            'the escape character alone' => ['!', ['bang!']],
            'a backslash is a letter' => ['a\\b', ['a\\b']],
            'plain text still finds what has it' => ['100', ['100% sure', '100 sure']],
            'case does not matter' => ['SURE', ['100% sure', '100 sure']],
            'nothing is found when nothing says it' => ['a%c', []],
        ];
    }

    // --- The site-wide search (the database driver) ---------------------------------------------------------

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_site_search_reads_blog_posts_by_the_letters_typed(string $term, array $expected): void
    {
        $author = User::factory()->create(['nickname' => 'Writer']);

        foreach (self::TITLES as $title) {
            Blog::factory()->published()->create(['title' => $title, 'user_id' => $author->id, 'excerpt' => 'An excerpt.', 'body' => '<p>Body text.</p>']);
        }

        $this->assertSame($this->sorted($expected), $this->searched($term, 'blogs'));
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_site_search_reads_threads_by_the_letters_typed(string $term, array $expected): void
    {
        $board = $this->board();
        $author = User::factory()->create(['nickname' => 'Starter']);

        foreach (self::TITLES as $title) {
            $this->thread($board, $author, $title);
        }

        $this->assertSame($this->sorted($expected), $this->searched($term, 'forum_threads'));
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_site_search_reads_faqs_by_the_letters_typed(string $term, array $expected): void
    {
        $category = FaqCategory::create(['name' => 'Letters', 'slug' => 'letters', 'description' => 'Letters.', 'order' => 1]);

        foreach (self::TITLES as $title) {
            Faq::create(['faq_category_id' => $category->id, 'question' => $title, 'answer' => 'An answer.', 'order' => 1, 'published' => true]);
        }

        $this->assertSame($this->sorted($expected), $this->searched($term, 'faqs'));
    }

    #[Test]
    public function the_site_search_finds_a_post_by_what_its_author_is_called_in_letters_too(): void
    {
        $literal = User::factory()->create(['nickname' => '50%_off']);
        $other = User::factory()->create(['nickname' => '50 x off']);

        Blog::factory()->published()->create(['title' => 'By the first', 'user_id' => $literal->id, 'excerpt' => 'An excerpt.']);
        Blog::factory()->published()->create(['title' => 'By the second', 'user_id' => $other->id, 'excerpt' => 'An excerpt.']);

        $this->assertSame(['By the first'], $this->searched('50%_off', 'blogs'));
    }

    #[Test]
    public function a_result_is_ranked_by_where_the_letters_are_not_by_a_pattern(): void
    {
        $board = $this->board();
        $author = User::factory()->create(['nickname' => 'Starter']);

        // The title of the first says `a_b`. The second says it only in its excerpt, but its title (`axb`) is what `a_b` would match if `_`
        // were "any character": ranked as a pattern it would have a title match as well, and come first.
        $this->thread($board, $author, 'a_b', 'Nothing to see.');
        $this->thread($board, $author, 'axb', 'It says a_b here.');

        $this->assertSame(['a_b', 'axb'], $this->searchedInOrder('a_b', 'forum_threads'));
    }

    // --- The forum -------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function a_board_is_searched_by_the_letters_typed(string $term, array $expected): void
    {
        $board = $this->board();
        $author = User::factory()->create(['nickname' => 'Starter']);

        foreach (self::TITLES as $title) {
            $this->thread($board, $author, $title);
        }

        $found = [];

        $this->get(route('forum.boards.show', ['board' => $board->slug, 'search' => $term]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found) {
                $page->where('threads.data', function ($threads) use (&$found) {
                    $found = collect($threads)->pluck('title')->all();

                    return true;
                });
            });

        $this->assertSame($this->sorted($expected), $this->sorted($found));
    }

    // --- The public lists ------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_blog_list_is_searched_by_the_letters_typed(string $term, array $expected): void
    {
        $author = User::factory()->create(['nickname' => 'Writer']);

        foreach (self::TITLES as $title) {
            Blog::factory()->published()->create(['title' => $title, 'user_id' => $author->id, 'excerpt' => 'An excerpt.']);
        }

        $found = [];

        $this->get(route('blogs.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found) {
                $page->where('blogs.data', function ($data) use (&$found) {
                    $found = collect($data)->pluck('title')->all();

                    return true;
                });
            });

        $this->assertSame($this->sorted($expected), $this->sorted($found));
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_shop_is_searched_by_the_letters_typed(string $term, array $expected): void
    {
        foreach (self::TITLES as $title) {
            Product::create(['name' => $title, 'slug' => Str::slug($title).'-'.Str::random(6), 'is_active' => true, 'description' => 'A description.']);
        }

        $found = [];

        $this->get(route('shop.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found) {
                $page->where('products.data', function ($data) use (&$found) {
                    $found = collect($data)->pluck('name')->all();

                    return true;
                });
            });

        $this->assertSame($this->sorted($expected), $this->sorted($found));
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('terms')]
    public function the_member_directory_is_searched_by_the_letters_typed(string $term, array $expected): void
    {
        foreach (self::TITLES as $nickname) {
            User::factory()->create(['nickname' => $nickname]);
        }

        $found = [];

        $this->get(route('members.index', ['q' => $term]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found) {
                $page->where('members.data', function ($data) use (&$found) {
                    $found = collect($data)->pluck('nickname')->all();

                    return true;
                });
            });

        $this->assertSame($this->sorted($expected), $this->sorted($found));
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('nicknames')]
    public function a_mention_is_suggested_by_the_letters_typed(string $term, array $expected): void
    {
        foreach (['a_b', 'axb', '100%', '100 percent', 'bang!', 'bang'] as $nickname) {
            User::factory()->create(['nickname' => $nickname]);
        }

        $asker = User::factory()->create(['nickname' => 'Asker']);

        $found = $this->actingAs($asker)
            ->getJson(route('forum.mentions.index', ['q' => $term]))
            ->assertOk()
            ->json('data.*.nickname');

        $this->assertSame($this->sorted($expected), $this->sorted($found));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function nicknames(): array
    {
        return [
            'an underscore is an underscore' => ['a_b', ['a_b']],
            'a percent is a percent' => ['100%', ['100%']],
            'the escape character is a letter' => ['bang!', ['bang!']],
            'a name that begins with it' => ['100', ['100%', '100 percent']],
        ];
    }

    #[Test]
    public function a_suggestion_that_begins_with_the_letters_comes_before_one_that_only_has_them(): void
    {
        // `a_b` is two letters and an underscore: `a b a_b` begins with something that fits it as a pattern, and only has the letters at the end.
        User::factory()->create(['nickname' => 'a b a_b']);
        User::factory()->create(['nickname' => 'a_bz']);

        $asker = User::factory()->create(['nickname' => 'Asker']);

        $this->assertSame(
            ['a_bz', 'a b a_b'],
            $this->actingAs($asker)->getJson(route('forum.mentions.index', ['q' => 'a_b']))->assertOk()->json('data.*.nickname'),
        );
    }

    // --- The support desk, for a member -----------------------------------------------------------------------

    #[Test]
    public function the_support_centre_searches_tickets_and_answers_by_the_letters_typed(): void
    {
        $member = User::factory()->create();
        $category = FaqCategory::create(['name' => 'Letters', 'slug' => 'letters', 'description' => 'Letters.', 'order' => 1]);

        foreach (['100% done', '100 done', 'a_b', 'axb'] as $subject) {
            SupportTicket::create(['user_id' => $member->id, 'subject' => $subject, 'body' => 'Help.', 'status' => 'open', 'priority' => 'low']);
            Faq::create(['faq_category_id' => $category->id, 'question' => $subject, 'answer' => 'An answer.', 'order' => 1, 'published' => true]);
        }

        foreach ([['100%', ['100% done']], ['a_b', ['a_b']], ['_', ['a_b']]] as [$term, $expected]) {
            $tickets = [];
            $questions = [];

            $this->actingAs($member)
                ->get(route('support', ['tickets_search' => $term, 'faqs_search' => $term]))
                ->assertOk()
                ->assertInertia(function (Assert $page) use (&$tickets, &$questions) {
                    $page->where('tickets.data', function ($data) use (&$tickets) {
                        $tickets = collect($data)->pluck('subject')->all();

                        return true;
                    })->where('faqs.groups', function ($groups) use (&$questions) {
                        $questions = collect($groups)->flatMap(fn (array $group) => collect($group['faqs'])->pluck('question'))->all();

                        return true;
                    });
                });

            $this->assertSame($expected, $this->sorted($tickets), "tickets found by {$term}");
            $this->assertSame($expected, $this->sorted($questions), "answers found by {$term}");
        }
    }

    // --- The support desk, for an agent -----------------------------------------------------------------------

    #[Test]
    public function the_support_admin_searches_tickets_and_answers_by_the_letters_typed(): void
    {
        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);

        $agent = User::factory()->create();
        $agent->assignRole('moderator');
        $agent->givePermissionTo('support.acp.view');

        $member = User::factory()->create(['nickname' => 'Requester', 'email' => 'requester@example.test']);
        $category = FaqCategory::create(['name' => 'Letters', 'slug' => 'letters', 'description' => 'Letters.', 'order' => 1]);

        foreach (['100% done', '100 done', 'a_b', 'axb'] as $subject) {
            SupportTicket::create(['user_id' => $member->id, 'subject' => $subject, 'body' => 'Help.', 'status' => 'open', 'priority' => 'low']);
            Faq::create(['faq_category_id' => $category->id, 'question' => $subject, 'answer' => 'An answer.', 'order' => 1, 'published' => true]);
        }

        foreach ([['100%', ['100% done']], ['a_b', ['a_b']], ['_', ['a_b']]] as [$term, $expected]) {
            $tickets = [];
            $questions = [];

            $this->actingAs($agent)
                ->get(route('acp.support.index', ['tickets_search' => $term, 'faqs_search' => $term]))
                ->assertOk()
                ->assertInertia(function (Assert $page) use (&$tickets, &$questions) {
                    $page->where('tickets.data', function ($data) use (&$tickets) {
                        $tickets = collect($data)->pluck('subject')->all();

                        return true;
                    })->where('faqs.data', function ($data) use (&$questions) {
                        $questions = collect($data)->pluck('question')->all();

                        return true;
                    });
                });

            $this->assertSame($expected, $this->sorted($tickets), "tickets found by {$term}");
            $this->assertSame($expected, $this->sorted($questions), "answers found by {$term}");
        }
    }

    // --- Helpers -----------------------------------------------------------------------------------------------

    /**
     * The titles the site search gives for a term, in one group, sorted.
     *
     * @return list<string>
     */
    private function searched(string $term, string $group): array
    {
        return $this->sorted($this->searchedInOrder($term, $group));
    }

    /**
     * @return list<string>
     */
    private function searchedInOrder(string $term, string $group): array
    {
        $items = app(GlobalSearchService::class)->search($term, ['types' => [$group]])['results'][$group]['items'];

        return array_map(fn (array $item): string => $item['title'], $items);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'General discussions', 'position' => 1]);

        return ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Talk', 'slug' => 'talk', 'description' => 'Talk', 'position' => 1]);
    }

    private function thread(ForumBoard $board, User $author, string $title, ?string $excerpt = null): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(6),
            'excerpt' => $excerpt ?? 'An excerpt.',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
    }
}
