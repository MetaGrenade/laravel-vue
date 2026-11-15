<?php

namespace Tests\Feature\Search;

use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\Coupon;
use App\Models\DataErasureRequest;
use App\Models\DataExport;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\SearchQuery;
use App\Models\SubscriptionPlan;
use App\Models\TebexPackage;
use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What staff type into the search box of a list in the admin area is letters, not a pattern (the public searches are in
 * {@see SearchTermsAreLettersTest}). In a `LIKE`, `_` is "any one character": a search for `a_b` also found `axb`, and `%` found everything.
 *
 * Every column a list searches gets two rows: one that says `a_b` and one that says `axb`. A search for `a_b` must find the first and not
 * the second. One column left as it was would show up as its decoy in the result.
 */
class AdminSearchTermsAreLettersTest extends TestCase
{
    use RefreshDatabase;

    private const LITERAL = 'a_b';

    private const DECOY = 'axb';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('admin')));
    }

    // --- The people and the audit trail ---------------------------------------------------------------------------

    #[Test]
    public function the_audit_log_is_searched_by_the_letters_typed(): void
    {
        $literal = [];

        foreach (['actor_label', 'subject_label', 'event', 'ip_address'] as $column) {
            $literal[] = AuditLog::factory()->create([$column => self::LITERAL])->id;
            AuditLog::factory()->create([$column => self::DECOY]);
        }

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.audit-log.index', ['search' => self::LITERAL]), 'entries.data'));
    }

    #[Test]
    public function the_member_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            User::factory()->create(['nickname' => self::LITERAL])->id,
            User::factory()->create(['email' => 'a_b@example.test'])->id,
            User::factory()->create()->assignRole(Role::findOrCreate(self::LITERAL))->id,
        ];

        User::factory()->create(['nickname' => self::DECOY]);
        User::factory()->create(['email' => 'axb@example.test']);
        User::factory()->create()->assignRole(Role::findOrCreate(self::DECOY));

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.users.index', ['search' => self::LITERAL]), 'users.data'));
    }

    #[Test]
    public function the_trust_and_safety_lists_are_searched_by_the_letters_typed(): void
    {
        $byNickname = User::factory()->create(['nickname' => self::LITERAL]);
        $byEmail = User::factory()->create(['email' => 'a_b@example.test']);
        $decoyNickname = User::factory()->create(['nickname' => self::DECOY]);
        $decoyEmail = User::factory()->create(['email' => 'axb@example.test']);

        $exports = [DataExport::factory()->for($byNickname)->create()->id, DataExport::factory()->for($byEmail)->create()->id];
        DataExport::factory()->for($decoyNickname)->create();
        DataExport::factory()->for($decoyEmail)->create();

        $erasures = [DataErasureRequest::factory()->for($byNickname)->create()->id, DataErasureRequest::factory()->for($byEmail)->create()->id];
        DataErasureRequest::factory()->for($decoyNickname)->create();
        DataErasureRequest::factory()->for($decoyEmail)->create();

        $url = route('acp.trust-safety.index', ['search' => self::LITERAL]);

        $this->assertSame($this->sorted($exports), $this->listed($url, 'exports.data'));
        $this->assertSame($this->sorted($erasures), $this->listed($url, 'erasureRequests.data'));
    }

    #[Test]
    public function the_token_log_is_searched_by_the_letters_typed(): void
    {
        $owner = User::factory()->create();

        $ofLiteral = $owner->createToken(self::LITERAL)->accessToken;
        $ofDecoy = $owner->createToken(self::DECOY)->accessToken;
        $other = $owner->createToken('Plain')->accessToken;

        // The name the log kept, and the name the token has now: either can be what is looked for.
        $literal = [
            $this->tokenLog($other, ['token_name' => self::LITERAL])->id,
            $this->tokenLog($ofLiteral, ['token_name' => 'Renamed'])->id,
        ];
        $this->tokenLog($other, ['token_name' => self::DECOY]);
        $this->tokenLog($ofDecoy, ['token_name' => 'Renamed again']);

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.tokens.index', ['token' => self::LITERAL]), 'tokenLogs.data'));
    }

    // --- The blog and the forum -----------------------------------------------------------------------------------

    #[Test]
    public function the_blog_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            Blog::factory()->create(['title' => self::LITERAL, 'slug' => 'plain-one'])->id,
            Blog::factory()->create(['title' => 'Plain two', 'slug' => self::LITERAL])->id,
            Blog::factory()->create(['title' => 'Plain three', 'slug' => 'plain-three', 'user_id' => User::factory()->create(['nickname' => self::LITERAL])->id])->id,
            Blog::factory()->create(['title' => 'Plain four', 'slug' => 'plain-four', 'user_id' => User::factory()->create(['email' => 'a_b@example.test'])->id])->id,
        ];

        Blog::factory()->create(['title' => self::DECOY, 'slug' => 'plain-five']);
        Blog::factory()->create(['title' => 'Plain six', 'slug' => self::DECOY]);
        Blog::factory()->create(['title' => 'Plain seven', 'slug' => 'plain-seven', 'user_id' => User::factory()->create(['nickname' => self::DECOY])->id]);
        Blog::factory()->create(['title' => 'Plain eight', 'slug' => 'plain-eight', 'user_id' => User::factory()->create(['email' => 'axb@example.test'])->id]);

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.blogs.index', ['search' => self::LITERAL]), 'blogs.data'));
    }

    #[Test]
    public function the_blog_list_reads_a_status_as_letters_too(): void
    {
        $draft = Blog::factory()->create(['title' => 'A draft', 'slug' => 'a-draft', 'status' => 'draft']);

        // `d_aft` would be "d, any character, a, f, t": no status says that.
        $this->assertSame([], $this->listed(route('acp.blogs.index', ['search' => 'd_aft']), 'blogs.data'));
        $this->assertSame([$draft->id], $this->listed(route('acp.blogs.index', ['search' => 'draft']), 'blogs.data'));
    }

    #[Test]
    public function the_reported_comments_are_searched_by_the_letters_typed(): void
    {
        $literal = $this->reportedComment(self::LITERAL);
        $this->reportedComment(self::DECOY);

        $this->assertSame([$literal->id], $this->listed(route('acp.blog-comments.index', ['search' => self::LITERAL]), 'reports.data', 'comment.id'));
    }

    #[Test]
    public function the_forum_reports_are_searched_by_the_title_of_the_thread_as_letters(): void
    {
        $category = ForumCategory::query()->create(['slug' => 'general', 'title' => 'General', 'description' => 'General talk', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'description' => 'News', 'position' => 1]);

        $literalThread = $this->thread($board, self::LITERAL);
        $decoyThread = $this->thread($board, self::DECOY);

        $threadReport = $this->reportOfThread($literalThread);
        $this->reportOfThread($decoyThread);
        $postReport = $this->reportOfPost($this->reply($literalThread));
        $this->reportOfPost($this->reply($decoyThread));

        $listed = $this->listed(route('acp.forums.reports.index', ['search' => self::LITERAL]), 'reports.data', fn (array $row) => $row['type'][0].$row['id']);

        $this->assertSame(['p'.$postReport->id, 't'.$threadReport->id], $listed);
    }

    // --- The shop -------------------------------------------------------------------------------------------------

    #[Test]
    public function the_product_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            Product::factory()->create(['name' => self::LITERAL, 'slug' => 'plain-one'])->id,
            Product::factory()->create(['name' => 'Plain two', 'slug' => self::LITERAL])->id,
        ];
        $withSku = Product::factory()->create(['name' => 'Plain three', 'slug' => 'plain-three']);
        ProductVariant::factory()->for($withSku)->create(['sku' => self::LITERAL]);
        $literal[] = $withSku->id;

        Product::factory()->create(['name' => self::DECOY, 'slug' => 'plain-four']);
        Product::factory()->create(['name' => 'Plain five', 'slug' => self::DECOY]);
        ProductVariant::factory()->for(Product::factory()->create(['name' => 'Plain six', 'slug' => 'plain-six']))->create(['sku' => self::DECOY]);

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.commerce.products.index', ['search' => self::LITERAL]), 'products.data'));
    }

    #[Test]
    public function the_order_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            $this->order(['number' => self::LITERAL])->public_id,
            $this->order(['customer_email' => 'a_b@example.test'])->public_id,
            $this->order(['customer_name' => self::LITERAL])->public_id,
        ];

        $this->order(['number' => self::DECOY]);
        $this->order(['customer_email' => 'axb@example.test']);
        $this->order(['customer_name' => self::DECOY]);

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.commerce.orders.index', ['search' => self::LITERAL]), 'orders.data', 'public_id'));
    }

    #[Test]
    public function the_review_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            ProductReview::factory()->create(['title' => self::LITERAL])->id,
            ProductReview::factory()->create(['body' => self::LITERAL])->id,
            ProductReview::factory()->for(Product::factory()->create(['name' => self::LITERAL]))->create()->id,
            ProductReview::factory()->for(User::factory()->create(['nickname' => self::LITERAL]))->create()->id,
            ProductReview::factory()->for(User::factory()->create(['email' => 'a_b@example.test']))->create()->id,
        ];

        ProductReview::factory()->create(['title' => self::DECOY]);
        ProductReview::factory()->create(['body' => self::DECOY]);
        ProductReview::factory()->for(Product::factory()->create(['name' => self::DECOY]))->create();
        ProductReview::factory()->for(User::factory()->create(['nickname' => self::DECOY]))->create();
        ProductReview::factory()->for(User::factory()->create(['email' => 'axb@example.test']))->create();

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.commerce.reviews.index', ['status' => 'all', 'search' => self::LITERAL]), 'reviews.data'));
    }

    #[Test]
    public function the_coupon_list_is_searched_by_the_letters_typed(): void
    {
        Coupon::factory()->create(['code' => 'A_B', 'description' => null]);
        Coupon::factory()->create(['code' => 'PLAIN', 'description' => self::LITERAL]);
        Coupon::factory()->create(['code' => 'AXB', 'description' => null]);
        Coupon::factory()->create(['code' => 'OTHER', 'description' => self::DECOY]);

        $this->assertSame(['A_B', 'PLAIN'], $this->listed(route('acp.commerce.coupons.index', ['search' => self::LITERAL]), 'coupons.data', 'code'));
    }

    #[Test]
    public function the_product_picker_of_the_coupon_form_reads_the_letters_typed(): void
    {
        Product::factory()->create(['name' => self::LITERAL, 'slug' => 'plain-one']);
        Product::factory()->create(['name' => self::DECOY, 'slug' => 'plain-two']);

        $names = $this->getJson(route('acp.commerce.coupons.product-search', ['search' => self::LITERAL]))->assertOk()->json('data.*.name');

        $this->assertSame([self::LITERAL], $names);
    }

    #[Test]
    public function the_gift_card_list_is_searched_by_the_letters_typed_in_a_note(): void
    {
        $literal = GiftCard::factory()->create(['note' => self::LITERAL]);
        GiftCard::factory()->create(['note' => self::DECOY]);

        $this->assertSame([$literal->id], $this->listed(route('acp.commerce.gift-cards.index', ['search' => self::LITERAL]), 'cards.data'));
    }

    #[Test]
    public function the_tebex_packages_are_searched_by_the_letters_typed(): void
    {
        $literal = [
            TebexPackage::factory()->create(['name' => self::LITERAL])->id,
            TebexPackage::factory()->create(['ident' => self::LITERAL])->id,
            TebexPackage::factory()->create(['slug' => self::LITERAL])->id,
        ];

        TebexPackage::factory()->create(['name' => self::DECOY]);
        TebexPackage::factory()->create(['ident' => self::DECOY]);
        TebexPackage::factory()->create(['slug' => self::DECOY]);

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.commerce.tebex.index', ['search' => self::LITERAL]), 'packages.data'));
    }

    // --- Billing and what is searched for ------------------------------------------------------------------------

    #[Test]
    public function the_invoice_list_is_searched_by_the_letters_typed(): void
    {
        $literal = [
            BillingInvoice::factory()->create(['stripe_id' => self::LITERAL])->id,
            BillingInvoice::factory()->for(User::factory()->create(['nickname' => self::LITERAL]))->create()->id,
            BillingInvoice::factory()->for(User::factory()->create(['email' => 'a_b@example.test']))->create()->id,
            BillingInvoice::factory()->for(SubscriptionPlan::factory()->create(['name' => self::LITERAL]), 'plan')->create()->id,
        ];

        BillingInvoice::factory()->create(['stripe_id' => self::DECOY]);
        BillingInvoice::factory()->for(User::factory()->create(['nickname' => self::DECOY]))->create();
        BillingInvoice::factory()->for(User::factory()->create(['email' => 'axb@example.test']))->create();
        BillingInvoice::factory()->for(SubscriptionPlan::factory()->create(['name' => self::DECOY]), 'plan')->create();

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.billing.invoices.index', ['search' => self::LITERAL]), 'invoices.data'));
    }

    #[Test]
    public function the_webhook_calls_are_searched_by_the_letters_typed(): void
    {
        $literal = [];

        foreach (['stripe_id', 'external_id', 'type'] as $column) {
            $literal[] = BillingWebhookCall::factory()->create([$column => self::LITERAL])->id;
            BillingWebhookCall::factory()->create([$column => self::DECOY]);
        }

        $this->assertSame($this->sorted($literal), $this->listed(route('acp.billing.webhooks.index', ['search' => self::LITERAL]), 'calls.data'));
    }

    #[Test]
    public function the_search_analytics_are_searched_by_the_letters_typed(): void
    {
        SearchQuery::factory()->create(['term' => self::LITERAL]);
        SearchQuery::factory()->create(['term' => self::DECOY]);

        $this->assertSame([self::LITERAL], $this->listed(route('acp.search-analytics.index', ['term' => self::LITERAL]), 'recentSearches.data', 'term'));
    }

    // --- What the tests above need ----------------------------------------------------------------------------------

    /**
     * What a list shows for the address, as the values of `$key` (a name in the row, or a way to get one from it), in order.
     *
     * @param  string|callable(array<string, mixed>): mixed  $key
     * @return list<mixed>
     */
    private function listed(string $url, string $path, string|callable $key = 'id'): array
    {
        $props = $this->get($url)->assertOk()->viewData('page')['props'];

        return $this->sorted(collect(Arr::get($props, $path))
            ->map(fn (array $row) => is_callable($key) ? $key($row) : Arr::get($row, $key))
            ->all());
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes): Order
    {
        // The number is made when the order is, from its id, unless it is given.
        return Order::factory()->create($attributes + ['customer_name' => 'Plain Customer']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function tokenLog(object $token, array $overrides): TokenLog
    {
        $log = new TokenLog([
            'personal_access_token_id' => $token->id,
            'token_name' => $overrides['token_name'],
            'route' => '/api/example',
            'method' => 'GET',
            'status' => 'success',
            'http_status' => 200,
        ]);
        $log->save();

        return $log;
    }

    private function reportedComment(string $body): BlogComment
    {
        $comment = BlogComment::factory()->create(['body' => $body]);

        BlogCommentReport::create([
            'blog_comment_id' => $comment->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Advert',
            'status' => BlogCommentReport::STATUS_PENDING,
        ]);

        return $comment;
    }

    private function thread(ForumBoard $board, string $title): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => User::factory()->create()->id,
            'title' => $title,
            'slug' => str($title)->slug().'-'.str()->random(6),
            'excerpt' => 'Thread under review',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    private function reply(ForumThread $thread): ForumPost
    {
        return ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => User::factory()->create()->id, 'body' => '<p>A reply</p>']);
    }

    private function reportOfThread(ForumThread $thread): ForumThreadReport
    {
        return ForumThreadReport::create([
            'forum_thread_id' => $thread->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam.',
            'status' => ForumThreadReport::STATUS_PENDING,
        ]);
    }

    private function reportOfPost(ForumPost $post): ForumPostReport
    {
        return ForumPostReport::create([
            'forum_post_id' => $post->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam.',
            'status' => ForumPostReport::STATUS_PENDING,
        ]);
    }
}
