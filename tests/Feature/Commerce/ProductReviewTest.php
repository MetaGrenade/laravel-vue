<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Commerce\Reviews\ProductReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;
use ZipArchive;

/**
 * What customers say about a product: who may say it, which reviews the public sees and what a rating is
 * made of.
 */
class ProductReviewTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        config([
            'commerce.reviews.enabled' => true,
            'commerce.reviews.require_purchase' => false,
            'commerce.reviews.approval' => 'unverified',
            'auth.must_verify_email' => false,
        ]);
    }

    private function product(): Product
    {
        return Product::factory()->priced('10.00')->stocked(10)->create();
    }

    /**
     * A paid order of the user's for the product.
     *
     * @param  array<string, mixed>  $orderState
     */
    private function bought(User $user, Product $product, array $orderState = []): Order
    {
        $order = Order::factory()->forUser($user)->paid()->create($orderState);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => '10.00',
            'subtotal' => '10.00',
            'description' => $product->name,
            'requires_shipping' => false,
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function review(array $overrides = []): array
    {
        return array_replace(['rating' => 4, 'title' => 'Does the job', 'body' => 'It arrived quickly and works well.'], $overrides);
    }

    private function postReview(User $user, Product $product, array $data = [])
    {
        return $this->actingAs($user)->post(route('shop.products.review.store', $product), $this->review($data));
    }

    private function reviews(Product $product, array $query = [], ?User $as = null)
    {
        $request = $as === null ? $this : $this->actingAs($as);

        return $request->get(route('shop.products.show', ['product' => $product, ...$query]));
    }

    // --- Who may write one -------------------------------------------------------------------------

    #[Test]
    public function a_guest_is_sent_to_sign_in_and_told_why_the_form_is_missing(): void
    {
        $product = $this->product();

        $this->post(route('shop.products.review.store', $product), $this->review())->assertRedirect(route('login'));
        $this->assertSame(0, ProductReview::query()->count());

        $this->reviews($product)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.can_write', false)
            ->where('reviews.reason', 'login')
            ->where('reviews.message', 'Sign in to write a review.'));
    }

    #[Test]
    public function a_signed_in_customer_can_write_a_review(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->postReview($user, $product)->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 4,
            'title' => 'Does the job',
            'verified_purchase' => false,
        ]);
        $this->reviews($product, as: $user)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.can_write', true)
            ->where('reviews.reason', null));
    }

    #[Test]
    public function only_buyers_can_write_when_the_shop_asks_for_a_purchase(): void
    {
        config(['commerce.reviews.require_purchase' => true]);
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();
        $product = $this->product();
        $this->bought($buyer, $product);

        $this->postReview($stranger, $product)->assertForbidden();
        $this->assertSame(0, ProductReview::query()->count());
        $this->reviews($product, as: $stranger)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.can_write', false)
            ->where('reviews.reason', 'purchase'));

        $this->postReview($buyer, $product)->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, ProductReview::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ordersThatDoNotCount(): array
    {
        return [
            'not paid yet' => [['status' => OrderStatus::Pending, 'payment_status' => OrderPaymentStatus::Unpaid]],
            'payment failed' => [['payment_status' => OrderPaymentStatus::Failed]],
            'refunded in full' => [['payment_status' => OrderPaymentStatus::Refunded]],
            'cancelled' => [['status' => OrderStatus::Cancelled]],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[Test]
    #[DataProvider('ordersThatDoNotCount')]
    public function an_order_that_was_not_kept_is_not_a_purchase(array $state): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->bought($user, $product, $state);

        $this->assertFalse(app(ProductReviews::class)->hasPurchased($user, $product));
    }

    #[Test]
    public function a_partly_refunded_order_is_still_a_purchase_and_so_is_one_owned_through_the_account(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->bought($user, $product, ['payment_status' => OrderPaymentStatus::PartiallyRefunded]);

        $this->assertTrue(app(ProductReviews::class)->hasPurchased($user, $product));
    }

    #[Test]
    public function somebody_elses_order_or_another_product_is_not_a_purchase(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();

        $this->bought($other, $product);
        $this->bought($user, $this->product());
        // A guest order that merely used this person's email address is not theirs.
        $guest = Order::factory()->paid()->create(['customer_email' => $user->email]);
        $guest->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00', 'description' => 'x', 'requires_shipping' => false]);

        $this->assertFalse(app(ProductReviews::class)->hasPurchased($user, $product));
    }

    #[Test]
    public function an_unconfirmed_email_cannot_review_when_the_site_asks_for_confirmation(): void
    {
        $user = User::factory()->unverified()->create();
        $product = $this->product();

        $this->postReview($user, $product)->assertRedirect();
        $this->assertSame(1, ProductReview::query()->count(), 'the site does not ask for confirmation');

        ProductReview::query()->delete();
        config(['auth.must_verify_email' => true]);

        $this->postReview($user, $product)->assertForbidden();
        $this->assertSame(0, ProductReview::query()->count());
        $this->reviews($product, as: $user)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.can_write', false)
            ->where('reviews.reason', 'verify_email'));
        $this->assertSame(ProductReviews::VERIFY_EMAIL, app(ProductReviews::class)->whyNot($user, $product));
    }

    #[Test]
    public function a_banned_customer_cannot_review(): void
    {
        $user = User::factory()->create(['is_banned' => true]);

        $this->assertSame(ProductReviews::BANNED, app(ProductReviews::class)->whyNot($user, $this->product()));
    }

    #[Test]
    public function nothing_can_be_written_or_shown_when_reviews_are_switched_off(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        ProductReview::factory()->for($product)->create();
        config(['commerce.reviews.enabled' => false]);

        $this->postReview($user, $product)->assertForbidden();
        $this->reviews($product, as: $user)->assertInertia(fn (Assert $page) => $page->where('reviews', null));
        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.rating', null));
        $this->assertStringNotContainsString('aggregateRating', $this->get(route('shop.products.show', $product))->getContent());
    }

    #[Test]
    public function a_product_that_is_not_on_sale_cannot_be_reviewed(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->inactive()->create();

        $this->postReview($user, $product)->assertNotFound();
        $this->assertSame(0, ProductReview::query()->count());
    }

    // --- Which ones wait for staff ----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool, 2: ReviewStatus}>
     */
    public static function approvalRules(): array
    {
        return [
            'unverified, from a buyer' => ['unverified', true, ReviewStatus::Published],
            'unverified, from a stranger' => ['unverified', false, ReviewStatus::Pending],
            'all, from a buyer' => ['all', true, ReviewStatus::Pending],
            'all, from a stranger' => ['all', false, ReviewStatus::Pending],
            'none, from a buyer' => ['none', true, ReviewStatus::Published],
            'none, from a stranger' => ['none', false, ReviewStatus::Published],
            'something unrecognised is the strict one' => ['sometimes', true, ReviewStatus::Pending],
        ];
    }

    #[Test]
    #[DataProvider('approvalRules')]
    public function the_approval_setting_decides_what_waits(string $approval, bool $buyer, ReviewStatus $expected): void
    {
        config(['commerce.reviews.approval' => $approval]);
        $user = User::factory()->create();
        $product = $this->product();

        if ($buyer) {
            $this->bought($user, $product);
        }

        $this->postReview($user, $product)->assertRedirect();

        $review = ProductReview::query()->sole();
        $this->assertSame($expected, $review->status);
        $this->assertSame($buyer, $review->verified_purchase, 'a review from a buyer says so');
    }

    #[Test]
    public function a_review_waiting_for_approval_is_not_shown_or_counted(): void
    {
        $product = $this->product();
        ProductReview::factory()->for($product)->pending()->create(['rating' => 1]);
        ProductReview::factory()->for($product)->rejected()->create(['rating' => 1]);
        ProductReview::factory()->for($product)->create(['rating' => 5]);

        $this->reviews($product)->assertInertia(fn (Assert $page) => $page
            ->has('reviews.items', 1)
            ->where('reviews.items.0.rating', 5)
            ->where('reviews.summary.count', 1)
            ->where('reviews.summary.average', 5)
            ->where('reviews.page.total', 1));
    }

    // --- One each, changing, deleting -------------------------------------------------------------

    #[Test]
    public function writing_again_changes_the_review_instead_of_adding_a_second(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->postReview($user, $product, ['rating' => 5, 'body' => 'First thoughts, quite positive.']);
        $this->postReview($user, $product, ['rating' => 2, 'body' => 'Second thoughts, much cooler.', 'title' => null]);

        $review = ProductReview::query()->sole();
        $this->assertSame(2, $review->rating);
        $this->assertSame('Second thoughts, much cooler.', $review->body);
        $this->assertNull($review->title);
    }

    #[Test]
    public function a_double_click_changes_the_review_the_first_click_wrote_instead_of_failing(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        // The other request gets its row in just before this one writes its own.
        $raced = false;
        ProductReview::creating(function () use ($user, $product, &$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('product_reviews')->insert([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'rating' => 1,
                    'body' => 'Written by the click that got there first.',
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->postReview($user, $product, ['rating' => 5, 'body' => 'Written by the click that came second.'])->assertSessionHas('success');

        $review = ProductReview::query()->sole();
        $this->assertTrue($raced);
        $this->assertSame(5, $review->rating);
        $this->assertSame('Written by the click that came second.', $review->body);
    }

    #[Test]
    public function changing_a_review_sends_it_back_for_approval_and_forgets_the_old_approval(): void
    {
        $staff = User::factory()->create();
        $user = User::factory()->create();
        $product = $this->product();
        $review = ProductReview::factory()->for($product)->for($user)->create([
            'status' => ReviewStatus::Published,
            'moderated_by' => $staff->id,
            'moderated_at' => now(),
        ]);

        $this->postReview($user, $product, ['body' => 'Now rewritten with a link to a shop.'])->assertSessionHas('success');

        $review->refresh();
        $this->assertSame(ReviewStatus::Pending, $review->status, 'the person is not a buyer, so the new text is checked too');
        $this->assertNull($review->moderated_by);
        $this->assertNull($review->moderated_at);
    }

    #[Test]
    public function a_buyers_changed_review_stays_published(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->bought($user, $product);

        $this->postReview($user, $product);
        $this->postReview($user, $product, ['rating' => 3, 'body' => 'Changed my mind a little bit.']);

        $review = ProductReview::query()->sole();
        $this->assertSame(ReviewStatus::Published, $review->status);
        $this->assertSame(3, $review->rating);
    }

    #[Test]
    public function a_rejected_review_can_be_neither_changed_nor_deleted_by_its_writer(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $review = ProductReview::factory()->for($product)->for($user)->rejected()->create(['body' => 'What staff turned down.']);

        $this->postReview($user, $product, ['body' => 'Trying to slip a new text past staff.'])->assertForbidden();
        $this->actingAs($user)->delete(route('shop.products.review.destroy', $product))->assertForbidden();

        $this->assertSame('What staff turned down.', $review->fresh()->body);
        $this->reviews($product, as: $user)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.can_write', false)
            ->where('reviews.mine.status', 'rejected')
            ->where('reviews.reason', null));
    }

    #[Test]
    public function a_customer_can_delete_their_own_review_and_only_their_own(): void
    {
        $ada = User::factory()->create();
        $bob = User::factory()->create();
        $product = $this->product();
        ProductReview::factory()->for($product)->for($ada)->create();
        $bobs = ProductReview::factory()->for($product)->for($bob)->create();

        $this->actingAs($ada)->delete(route('shop.products.review.destroy', $product))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('product_reviews', ['user_id' => $ada->id]);
        $this->assertDatabaseHas('product_reviews', ['id' => $bobs->id]);

        // Ada has none left to delete, and cannot reach Bob's.
        $this->actingAs($ada)->delete(route('shop.products.review.destroy', $product))->assertNotFound();
        $this->assertDatabaseHas('product_reviews', ['id' => $bobs->id]);
    }

    #[Test]
    public function the_policy_lets_a_writer_change_what_staff_have_not_rejected(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $mine = fn (ReviewStatus $status) => ProductReview::factory()->for($user)->create(['status' => $status]);

        foreach ([ReviewStatus::Pending, ReviewStatus::Published] as $status) {
            $this->assertTrue(Gate::forUser($user)->allows('update', $mine($status)));
            $this->assertTrue(Gate::forUser($user)->allows('delete', $mine($status)));
        }

        $rejected = $mine(ReviewStatus::Rejected);
        $this->assertFalse(Gate::forUser($user)->allows('update', $rejected));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $rejected));
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $mine(ReviewStatus::Published)));
        $this->assertFalse(Gate::forUser($stranger)->allows('delete', $mine(ReviewStatus::Published)));
    }

    // --- What is accepted --------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badReviews(): array
    {
        return [
            'no rating' => [['rating' => null], 'rating'],
            'rating too low' => [['rating' => 0], 'rating'],
            'rating too high' => [['rating' => 6], 'rating'],
            'rating that is not a number' => [['rating' => 'lots'], 'rating'],
            'no text' => [['body' => ''], 'body'],
            'text that is only spaces' => [['body' => '            '], 'body'],
            'text that is too short' => [['body' => 'Nice.'], 'body'],
            'text that is too long' => [['body' => str_repeat('a', 5001)], 'body'],
            'a headline that is too long' => [['title' => str_repeat('a', 121)], 'title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('badReviews')]
    public function a_review_has_to_be_complete(array $data, string $field): void
    {
        $this->postReview(User::factory()->create(), $this->product(), $data)->assertSessionHasErrors($field);

        $this->assertSame(0, ProductReview::query()->count());
    }

    #[Test]
    public function the_headline_is_optional_and_the_text_is_kept_exactly_as_written(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $text = "<script>alert('x')</script> **not bold**\n\nSecond paragraph & more.";

        $this->postReview($user, $product, ['title' => null, 'body' => $text])->assertSessionHasNoErrors();

        $this->assertSame($text, ProductReview::query()->sole()->body, 'plain text: the page shows it escaped, so it is never run');
        $this->assertNull(ProductReview::query()->sole()->title);
    }

    // --- What the product page shows ---------------------------------------------------------------

    #[Test]
    public function the_rating_is_made_of_published_reviews_only(): void
    {
        $product = $this->product();

        foreach ([5, 5, 4, 2] as $stars) {
            ProductReview::factory()->for($product)->rated($stars)->create();
        }

        $this->reviews($product)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.summary.count', 4)
            ->where('reviews.summary.average', 4)
            ->where('reviews.summary.distribution', [5 => 2, 4 => 1, 3 => 0, 2 => 1, 1 => 0]));
    }

    #[Test]
    public function the_average_is_rounded_to_one_decimal(): void
    {
        $product = $this->product();

        foreach ([5, 4, 4] as $stars) {
            ProductReview::factory()->for($product)->rated($stars)->create();
        }

        $this->assertSame(4.3, app(ProductReviews::class)->summary($product)['average']);
    }

    #[Test]
    public function a_product_with_no_reviews_has_no_average(): void
    {
        $product = $this->product();

        $this->assertSame(['count' => 0, 'average' => null, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]], app(ProductReviews::class)->summary($product));
        $this->reviews($product)->assertInertia(fn (Assert $page) => $page->has('reviews.items', 0)->where('reviews.summary.average', null));
    }

    #[Test]
    public function reviews_come_newest_first_or_in_the_order_asked_for(): void
    {
        $product = $this->product();
        $old = ProductReview::factory()->for($product)->rated(3)->create(['created_at' => now()->subDays(3)]);
        $mid = ProductReview::factory()->for($product)->rated(5)->create(['created_at' => now()->subDays(2)]);
        $new = ProductReview::factory()->for($product)->rated(1)->create(['created_at' => now()->subDay()]);

        $ids = fn (array $query) => collect($this->reviews($product, $query)->viewData('page')['props']['reviews']['items'])->pluck('id')->all();

        $this->assertSame([$new->id, $mid->id, $old->id], $ids([]));
        $this->assertSame([$mid->id, $old->id, $new->id], $ids(['review_sort' => 'highest']));
        $this->assertSame([$new->id, $old->id, $mid->id], $ids(['review_sort' => 'lowest']));
        $this->assertSame([$new->id, $mid->id, $old->id], $ids(['review_sort' => 'nonsense']), 'an unknown order is the default one');
        $this->reviews($product, ['review_sort' => 'nonsense'])->assertInertia(fn (Assert $page) => $page->where('reviews.sort', 'newest'));
        $this->reviews($product, ['review_sort' => 'lowest'])->assertInertia(fn (Assert $page) => $page->where('reviews.sort', 'lowest'));
    }

    #[Test]
    public function reviews_are_paged(): void
    {
        $product = $this->product();
        ProductReview::factory()->for($product)->count(12)->create();

        $this->reviews($product)->assertInertia(fn (Assert $page) => $page
            ->has('reviews.items', 10)
            ->where('reviews.page', ['current' => 1, 'last' => 2, 'total' => 12]));
        $this->reviews($product, ['reviews_page' => 2])->assertInertia(fn (Assert $page) => $page
            ->has('reviews.items', 2)
            ->where('reviews.page.current', 2));
    }

    #[Test]
    public function a_review_shows_who_wrote_it_without_giving_away_who_they_are(): void
    {
        $product = $this->product();
        $writer = User::factory()->create(['nickname' => 'Ada_L', 'email' => 'ada.private@example.test']);
        $buyer = User::factory()->create();
        ProductReview::factory()->for($product)->for($writer)->create();
        ProductReview::factory()->for($product)->for($buyer)->verified()->create();

        $response = $this->reviews($product, as: $buyer);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('reviews.items.0.mine', true)
            ->where('reviews.items.0.verified_purchase', true)
            ->where('reviews.items.1.author', 'Ada_L')
            ->where('reviews.items.1.mine', false)
            ->where('reviews.items.1.verified_purchase', false));
        $this->assertStringNotContainsString('ada.private@example.test', $response->getContent());
    }

    #[Test]
    public function the_visitors_own_review_is_sent_whatever_state_it_is_in(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 3, 'title' => 'Mine']);

        $this->reviews($product, as: $user)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.mine.status', 'pending')
            ->where('reviews.mine.status_label', 'Awaiting approval')
            ->where('reviews.mine.rating', 3)
            ->where('reviews.mine.title', 'Mine')
            ->where('reviews.can_write', true)
            ->has('reviews.items', 0));
    }

    #[Test]
    public function search_engines_are_told_the_rating(): void
    {
        $product = $this->product();
        ProductReview::factory()->for($product)->rated(5)->count(2)->create();
        ProductReview::factory()->for($product)->rated(4)->create();
        ProductReview::factory()->for($product)->rated(1)->pending()->create();

        $html = $this->get(route('shop.products.show', $product))->getContent();

        $this->assertStringContainsString('"@type":"AggregateRating"', $html);
        $this->assertStringContainsString('"ratingValue":4.7', $html);
        $this->assertStringContainsString('"reviewCount":3', $html);
    }

    #[Test]
    public function a_product_nobody_reviewed_says_nothing_about_a_rating(): void
    {
        $this->assertStringNotContainsString('aggregateRating', $this->get(route('shop.products.show', $this->product()))->getContent());
    }

    // --- Listings ----------------------------------------------------------------------------------

    #[Test]
    public function the_catalogue_shows_each_products_rating(): void
    {
        $rated = Product::factory()->priced('5.00')->stocked(3)->create(['name' => 'Rated']);
        Product::factory()->priced('5.00')->stocked(3)->create(['name' => 'Unrated']);
        Product::factory()->priced('5.00')->stocked(3)->create(['name' => 'Waiting'])
            ->reviews()->save(ProductReview::factory()->pending()->make(['user_id' => User::factory()->create()->id]));
        ProductReview::factory()->for($rated)->rated(5)->create();
        ProductReview::factory()->for($rated)->rated(4)->create();

        $ratings = collect($this->get(route('shop.index'))->viewData('page')['props']['products']['data'])->pluck('rating', 'name')->all();

        $this->assertEquals(['average' => 4.5, 'count' => 2], $ratings['Rated']);
        $this->assertNull($ratings['Unrated']);
        $this->assertNull($ratings['Waiting'], 'a review that is not published does not make a rating');
    }

    #[Test]
    public function the_catalogue_asks_for_ratings_once_however_many_products_there_are(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('shop.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        ProductReview::factory()->for($this->product())->create();
        $small = $count();

        foreach (range(1, 6) as $unused) {
            ProductReview::factory()->for($this->product())->create();
        }

        $this->assertSame($small, $count());
    }

    #[Test]
    public function the_order_history_links_a_line_to_its_product_while_it_is_on_sale(): void
    {
        $user = User::factory()->create();
        $onSale = $this->product();
        $gone = Product::factory()->inactive()->create();
        $this->bought($user, $onSale);
        $this->bought($user, $gone);

        $links = collect($this->actingAs($user)->get(route('shop.orders'))->viewData('page')['props']['orders']['data'])
            ->flatMap(fn ($order) => $order['items'])
            ->pluck('product_url')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([null, route('shop.products.show', $onSale)], $links);
    }

    // --- Privacy -----------------------------------------------------------------------------------

    #[Test]
    public function a_data_export_includes_wishlist_and_reviews(): void
    {
        Storage::fake('local');
        Notification::fake();
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Widget']);
        $user->wishlistItems()->create(['product_id' => $product->id]);
        ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 2, 'title' => 'Meh', 'body' => 'Not what I hoped for.']);

        $export = DataExport::factory()->for($user)->create();
        (new GenerateUserDataExport($export->id))->handle();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->file_path)) === true);
        $payload = json_decode((string) $zip->getFromName('export.json'), true, flags: JSON_THROW_ON_ERROR);
        $csv = (string) $zip->getFromName('export.csv');
        $zip->close();

        $this->assertSame('Widget', $payload['wishlist'][0]['product']);
        $this->assertSame(['rating' => 2, 'title' => 'Meh', 'body' => 'Not what I hoped for.', 'status' => 'pending'], array_intersect_key($payload['product_reviews'][0], array_flip(['rating', 'title', 'body', 'status'])));
        $this->assertStringContainsString('wishlist_item', $csv);
        $this->assertStringContainsString('product_review', $csv);
    }

    #[Test]
    public function deleting_a_product_or_an_account_takes_the_reviews_with_it(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $kept = $this->product();
        ProductReview::factory()->for($product)->for($user)->create();
        ProductReview::factory()->for($product)->for($other)->create();
        ProductReview::factory()->for($kept)->for($user)->create();

        $product->delete();
        $this->assertSame(1, ProductReview::query()->count());

        $user->delete();
        $this->assertSame(0, ProductReview::query()->count());
    }
}
