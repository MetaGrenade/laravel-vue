<?php

namespace Tests\Feature\Performance;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
use App\Models\BlogView;
use App\Models\Cart;
use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\Faq;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\MemberFollow;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReputationEvent;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Commerce\CartManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountsQueries;
use Tests\TestCase;

/**
 * What the pages people read most cost in queries (docs/query-budgets.md): the same whether the site has one row of what a page lists or
 * many, and under a ceiling. A ceiling is not a target. The number says how much the page asks for today with some room, so a page that
 * starts asking twice as much fails here instead of getting slower unnoticed; raise it in the change that needs the query, and say why.
 */
class QueryBudgetTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    // --- The front of the site ---------------------------------------------------------------------

    #[Test]
    public function the_home_page(): void
    {
        $this->assertQueryCost('the home page', fn () => null, fn () => $this->get(route('home'))->assertOk(), 12);
    }

    #[Test]
    public function the_blog_list(): void
    {
        $this->assertQueryCost('the blog list', fn () => $this->blog(), fn () => $this->get(route('blogs.index'))->assertOk(), 23);
    }

    #[Test]
    public function a_blog_post_with_its_comments(): void
    {
        $blog = $this->blog();

        $this->assertQueryCost(
            'a blog post',
            fn () => BlogComment::factory()->for($blog)->create(),
            fn () => $this->get(route('blogs.view', $blog->slug))->assertOk(),
            28,
        );
    }

    #[Test]
    public function the_comments_of_a_blog_post(): void
    {
        $blog = $this->blog();

        $this->assertQueryCost(
            'the comments of a blog post',
            fn () => BlogComment::factory()->for($blog)->create(),
            fn () => $this->getJson(route('blogs.comments.index', $blog))->assertOk(),
            12,
        );
    }

    // --- The forum ---------------------------------------------------------------------------------

    #[Test]
    public function the_forum_index(): void
    {
        $category = $this->category();

        $this->assertQueryCost(
            'the forum index',
            fn () => $this->thread($this->board($category)),
            fn () => $this->get(route('forum.index'))->assertOk(),
            29,
        );
    }

    #[Test]
    public function a_forum_board(): void
    {
        $board = $this->board();

        $this->assertQueryCost(
            'a forum board',
            fn () => $this->thread($board),
            fn () => $this->get(route('forum.boards.show', $board))->assertOk(),
            22,
        );
    }

    #[Test]
    public function a_forum_thread(): void
    {
        $board = $this->board();
        $thread = $this->thread($board);

        $this->assertQueryCost(
            'a forum thread',
            fn () => $this->reply($thread),
            fn () => $this->get(route('forum.threads.show', [$board, $thread]))->assertOk(),
            33,
        );
    }

    // --- The people --------------------------------------------------------------------------------

    #[Test]
    public function the_member_list(): void
    {
        $this->assertQueryCost('the member list', fn () => User::factory()->create(), fn () => $this->get(route('members.index'))->assertOk(), 14);
    }

    #[Test]
    public function a_member_profile(): void
    {
        $member = User::factory()->create();

        $this->assertQueryCost(
            'a member profile',
            fn () => $this->reply($this->thread($this->board(), $member), $member),
            fn () => $this->get(route('members.show', $member))->assertOk(),
            27,
        );
    }

    #[Test]
    public function the_leaderboard(): void
    {
        $this->assertQueryCost(
            'the leaderboard',
            function () {
                $member = User::factory()->create(['reputation_points' => 10]);
                $post = $this->reply($this->thread($this->board(), $member), $member);
                ReputationEvent::create(['user_id' => $member->id, 'event' => 'forum_post_created', 'points' => 5, 'source_type' => $post->getMorphClass(), 'source_id' => $post->id]);
            },
            fn () => $this->get(route('leaderboard'))->assertOk(),
            23,
        );
    }

    // --- The shop ----------------------------------------------------------------------------------

    #[Test]
    public function the_catalogue(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the catalogue',
            function () use ($user) {
                $product = $this->product();
                ProductReview::factory()->for($product)->create();
                WishlistItem::factory()->for($user)->for($product)->create();
            },
            fn () => $this->actingAs($user)->get(route('shop.index'))->assertOk(),
            38,
        );
    }

    #[Test]
    public function a_product_with_its_reviews(): void
    {
        $product = $this->product();

        $this->assertQueryCost(
            'a product',
            fn () => ProductReview::factory()->for($product)->create(),
            fn () => $this->get(route('shop.products.show', $product))->assertOk(),
            30,
        );
    }

    #[Test]
    public function the_cart(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'currency' => 'USD']);

        $this->assertQueryCost(
            'the cart',
            function () use ($cart) {
                $product = $this->product();
                CartManager::addItem($cart, $product, null, $product->prices()->firstOrFail(), 1);
            },
            fn () => $this->actingAs($user)->get(route('shop.cart'))->assertOk(),
            30,
        );
    }

    #[Test]
    public function the_wishlist(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the wishlist',
            fn () => WishlistItem::factory()->for($user)->for($this->product())->create(),
            fn () => $this->actingAs($user)->get(route('shop.wishlist'))->assertOk(),
            32,
        );
    }

    #[Test]
    public function the_order_history(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the order history',
            fn () => $this->order($user),
            fn () => $this->actingAs($user)->get(route('shop.orders'))->assertOk(),
            30,
        );
    }

    #[Test]
    public function the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the dashboard',
            function () use ($user) {
                $this->order($user);
                SupportTicket::factory()->create(['user_id' => $user->id]);
                $this->reply($this->thread($this->board(), $user), $user);
                BlogView::factory()->create(['blog_id' => $this->blog()->id, 'user_id' => $user->id]);
            },
            fn () => $this->actingAs($user)->get(route('dashboard'))->assertOk(),
            72,
        );
    }

    #[Test]
    public function the_pricing_page(): void
    {
        $this->assertQueryCost('the pricing page', fn () => SubscriptionPlan::factory()->create(), fn () => $this->get(route('pricing'))->assertOk(), 14);
    }

    // --- Help --------------------------------------------------------------------------------------

    #[Test]
    public function the_support_centre(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the support centre',
            function () use ($user) {
                SupportTicket::factory()->create(['user_id' => $user->id]);
                Faq::factory()->create(['published' => true]);
            },
            fn () => $this->actingAs($user)->get(route('support'))->assertOk(),
            29,
        );
    }

    #[Test]
    public function a_support_ticket_with_its_messages(): void
    {
        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id]);

        $this->assertQueryCost(
            'a support ticket',
            fn () => SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => User::factory()->create()->id, 'body' => 'An answer.']),
            fn () => $this->actingAs($user)->get(route('support.tickets.show', $ticket))->assertOk(),
            27,
        );
    }

    #[Test]
    public function search_results(): void
    {
        $this->assertQueryCost(
            'search results',
            function () {
                Blog::factory()->published()->create(['title' => 'Lighthouse '.Str::random(5)]);
                Faq::factory()->create(['published' => true, 'question' => 'Lighthouse '.Str::random(5)]);
                $this->product(['name' => 'Lighthouse '.Str::random(5)]);
                $this->thread($this->board())->update(['title' => 'Lighthouse '.Str::random(5)]);
            },
            fn () => $this->get(route('search.results', ['q' => 'Lighthouse']))->assertOk(),
            30,
        );
    }

    #[Test]
    public function the_inbox(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the inbox',
            function () use ($user) {
                $other = User::factory()->create();
                $conversation = DirectConversation::create([
                    'user_low_id' => min($user->id, $other->id),
                    'user_high_id' => max($user->id, $other->id),
                    'last_message_at' => now(),
                ]);
                DirectMessage::create(['direct_conversation_id' => $conversation->id, 'user_id' => $other->id, 'body' => 'Hello']);
            },
            fn () => $this->actingAs($user)->get(route('messages.index'))->assertOk(),
            25,
        );
    }

    #[Test]
    public function the_members_a_member_follows(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the members a member follows',
            function () use ($user) {
                $followed = User::factory()->create();
                MemberFollow::create(['user_id' => $user->id, 'followed_user_id' => $followed->id]);
                $this->reply($this->thread($this->board(), $followed), $followed);
            },
            fn () => $this->actingAs($user)->get(route('forum.following'))->assertOk(),
            34,
        );
    }

    #[Test]
    public function the_suggestions_for_a_mention(): void
    {
        $user = User::factory()->create();

        $this->assertQueryCost(
            'the suggestions for a mention',
            fn () => User::factory()->create(),
            fn () => $this->actingAs($user)->getJson(route('forum.mentions.index', ['q' => 'a']))->assertOk(),
            9,
        );
    }

    // --- Feeds and the sitemap ---------------------------------------------------------------------

    #[Test]
    public function the_blog_feed(): void
    {
        $this->assertQueryCost('the blog feed', fn () => $this->blog(), fn () => $this->get(route('blogs.feed'))->assertOk(), 8);
    }

    #[Test]
    public function the_forum_feed(): void
    {
        $board = $this->board();

        $this->assertQueryCost('the forum feed', fn () => $this->thread($board), fn () => $this->get(route('forum.feed'))->assertOk(), 8);
    }

    #[Test]
    public function the_sitemap(): void
    {
        $board = $this->board();

        $this->assertQueryCost(
            'the sitemap',
            function () use ($board) {
                $this->blog();
                $this->thread($board);
                $this->product();
            },
            fn () => $this->get(route('sitemap'))->assertOk(),
            25,
        );
    }

    // --- The staff area ----------------------------------------------------------------------------

    #[Test]
    public function the_member_list_of_the_staff_area(): void
    {
        $staff = $this->staff();

        $this->assertQueryCost('the staff member list', fn () => User::factory()->create(), fn () => $this->actingAs($staff)->get(route('acp.users.index'))->assertOk(), 30);
    }

    #[Test]
    public function the_order_list_of_the_staff_area(): void
    {
        $staff = $this->staff();

        $this->assertQueryCost('the staff order list', fn () => $this->order(User::factory()->create()), fn () => $this->actingAs($staff)->get(route('acp.commerce.orders.index'))->assertOk(), 25);
    }

    #[Test]
    public function the_product_list_of_the_staff_area(): void
    {
        $staff = $this->staff();

        $this->assertQueryCost('the staff product list', fn () => $this->product(), fn () => $this->actingAs($staff)->get(route('acp.commerce.products.index'))->assertOk(), 30);
    }

    #[Test]
    public function the_blog_list_of_the_staff_area(): void
    {
        $staff = $this->staff();

        $this->assertQueryCost('the staff blog list', fn () => $this->blog(), fn () => $this->actingAs($staff)->get(route('acp.blogs.index'))->assertOk(), 37);
    }

    #[Test]
    public function the_ticket_list_of_the_staff_area(): void
    {
        $staff = $this->staff();

        $this->assertQueryCost(
            'the staff ticket list',
            fn () => SupportTicket::factory()->create(),
            fn () => $this->actingAs($staff)->get(route('acp.support.index'))->assertOk(),
            37,
        );
    }

    #[Test]
    public function the_reports_of_the_staff_area(): void
    {
        $staff = $this->staff();
        $board = $this->board();

        $this->assertQueryCost(
            'the staff report list',
            function () use ($board) {
                $thread = $this->thread($board);
                ForumThreadReport::create([
                    'forum_thread_id' => $thread->id,
                    'reporter_id' => User::factory()->create()->id,
                    'reason_category' => 'spam',
                    'reason' => 'Looks like spam.',
                    'status' => ForumThreadReport::STATUS_PENDING,
                ]);
            },
            fn () => $this->actingAs($staff)->get(route('acp.forums.reports.index'))->assertOk(),
            30,
        );
    }

    // --- What the tests above set up ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->priced('10.00')->stocked(10)->create($attributes);
    }

    private function order(User $user): Order
    {
        $order = Order::factory()->forUser($user)->paid()->create(['grand_total' => '10.00']);
        $order->items()->create([
            'product_id' => $this->product()->id,
            'quantity' => 1,
            'unit_price' => '10.00',
            'subtotal' => '10.00',
            'description' => 'Thing',
            'requires_shipping' => false,
            'delivers_keys' => false,
        ]);

        return $order;
    }

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return tap(User::factory()->create())->assignRole('admin');
    }

    private function blog(): Blog
    {
        $blog = Blog::factory()->published()->create();
        $blog->categories()->attach(BlogCategory::factory()->create());
        $blog->tags()->attach(BlogTag::factory()->create());

        return $blog;
    }

    private function category(): ForumCategory
    {
        return ForumCategory::create(['title' => 'General', 'slug' => 'general-'.Str::random(5), 'description' => 'Chat', 'position' => 1]);
    }

    private function board(?ForumCategory $category = null): ForumBoard
    {
        $category ??= $this->category();

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Board '.Str::random(5),
            'slug' => 'board-'.Str::random(8),
            'description' => 'A board',
            'position' => 1,
        ]);
    }

    private function thread(ForumBoard $board, ?User $author = null): ForumThread
    {
        $author ??= User::factory()->create();
        $title = 'Thread '.Str::random(6);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);

        $this->reply($thread, $author);

        return $thread;
    }

    private function reply(ForumThread $thread, ?User $author = null): ForumPost
    {
        $author ??= User::factory()->create();

        return ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => 'A reply.']);
    }
}
