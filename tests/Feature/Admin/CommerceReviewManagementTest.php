<?php

namespace Tests\Feature\Admin;

use App\Enums\ReviewStatus;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staff deciding which product reviews the public sees.
 */
class CommerceReviewManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.currency' => 'USD', 'commerce.reviews.enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(User $as, array $query = []): array
    {
        return $this->actingAs($as)->get(route('acp.commerce.reviews.index', $query))->viewData('page')['props']['reviews']['data'];
    }

    // --- Access ------------------------------------------------------------------------------------

    #[Test]
    public function the_pages_need_a_signed_in_user_with_commerce_access(): void
    {
        $review = ProductReview::factory()->pending()->create();

        $this->get(route('acp.commerce.reviews.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.reviews.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->patch(route('acp.commerce.reviews.update', $review), ['status' => 'published'])->assertForbidden();
        $this->actingAs(User::factory()->create())->delete(route('acp.commerce.reviews.destroy', $review))->assertForbidden();

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $review = ProductReview::factory()->pending()->create();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        $this->actingAs($viewer)->get(route('acp.commerce.reviews.index'))->assertOk();
        $this->actingAs($viewer)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'published'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.reviews.destroy', $review))->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.edit');
        $this->actingAs($viewer)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'published'])->assertRedirect();
        $this->actingAs($viewer)->delete(route('acp.commerce.reviews.destroy', $review))->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.delete');
        $this->actingAs($viewer)->delete(route('acp.commerce.reviews.destroy', $review))->assertRedirect();
        $this->assertDatabaseMissing('product_reviews', ['id' => $review->id]);
    }

    #[Test]
    public function the_shop_section_being_off_hides_the_pages(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.reviews.index'))->assertNotFound();
    }

    // --- The list ----------------------------------------------------------------------------------

    #[Test]
    public function the_list_starts_with_what_is_waiting_and_counts_every_state(): void
    {
        ProductReview::factory()->count(2)->pending()->create();
        ProductReview::factory()->count(3)->create();
        ProductReview::factory()->rejected()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.reviews.index'))->assertInertia(fn (Assert $page) => $page
            ->component('acp/CommerceReviews')
            ->where('filters.status', 'pending')
            ->has('reviews.data', 2)
            ->where('counts', ['pending' => 2, 'published' => 3, 'rejected' => 1, 'all' => 6]));
    }

    #[Test]
    public function the_list_can_be_narrowed_by_state_and_rating(): void
    {
        $admin = $this->admin();
        ProductReview::factory()->rated(5)->create();
        ProductReview::factory()->rated(2)->create();
        ProductReview::factory()->rated(2)->rejected()->create();
        $waiting = ProductReview::factory()->rated(4)->pending()->create();

        $this->assertCount(2, $this->rows($admin, ['status' => 'published']));
        $this->assertCount(1, $this->rows($admin, ['status' => 'rejected']));
        $this->assertCount(4, $this->rows($admin, ['status' => 'all']));
        $this->assertCount(2, $this->rows($admin, ['status' => 'all', 'rating' => 2]));
        $this->assertSame([$waiting->id], array_column($this->rows($admin, ['status' => 'nonsense']), 'id'), 'an unknown state is the default one');
        $this->assertCount(4, $this->rows($admin, ['status' => 'all', 'rating' => 9]), 'an impossible rating filters nothing');
    }

    #[Test]
    public function the_list_can_be_searched_by_text_product_or_customer(): void
    {
        $admin = $this->admin();
        $widget = Product::factory()->create(['name' => 'Blue Widget']);
        $ada = User::factory()->create(['nickname' => 'AdaLovelace', 'email' => 'ada@example.test']);
        ProductReview::factory()->for($widget)->create(['title' => 'Lovely', 'body' => 'Sturdy and well made.']);
        ProductReview::factory()->for($ada)->create(['title' => 'Fine', 'body' => 'Arrived on time.']);
        ProductReview::factory()->create(['title' => 'Other', 'body' => 'Nothing to see.']);

        $found = fn (string $term) => collect($this->rows($admin, ['status' => 'all', 'search' => $term]))->pluck('title')->sort()->values()->all();

        $this->assertSame(['Lovely'], $found('sturdy'), 'the text');
        $this->assertSame(['Lovely'], $found('Blue Widget'), 'the product');
        $this->assertSame(['Fine'], $found('AdaLovelace'), 'the customer');
        $this->assertSame(['Fine'], $found('ada@example.test'), 'the customer by email');
        $this->assertSame([], $found('no such thing'));
    }

    #[Test]
    public function a_row_has_what_staff_need_to_decide(): void
    {
        $product = Product::factory()->create(['name' => 'Blue Widget']);
        $author = User::factory()->create(['nickname' => 'Ada', 'email' => 'ada@example.test']);
        ProductReview::factory()->for($product)->for($author)->pending()->verified()->create(['rating' => 3, 'title' => 'Mixed', 'body' => 'Some good, some bad.']);

        $this->actingAs($this->admin())->get(route('acp.commerce.reviews.index'))->assertInertia(fn (Assert $page) => $page
            ->where('reviews.data.0.rating', 3)
            ->where('reviews.data.0.title', 'Mixed')
            ->where('reviews.data.0.body', 'Some good, some bad.')
            ->where('reviews.data.0.status', 'pending')
            ->where('reviews.data.0.verified_purchase', true)
            ->where('reviews.data.0.product.name', 'Blue Widget')
            ->where('reviews.data.0.author.nickname', 'Ada')
            ->where('reviews.data.0.author.email', 'ada@example.test')
            ->where('can.edit', true)
            ->where('can.delete', true));
    }

    #[Test]
    public function the_list_is_paged(): void
    {
        ProductReview::factory()->count(23)->pending()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.reviews.index'))->assertInertia(fn (Assert $page) => $page
            ->has('reviews.data', 20)
            ->where('reviews.meta.total', 23)
            ->where('reviews.meta.last_page', 2));
    }

    // --- Deciding ----------------------------------------------------------------------------------

    #[Test]
    public function a_review_can_be_published_rejected_or_put_back_and_the_decision_is_recorded(): void
    {
        $admin = $this->admin();
        $review = ProductReview::factory()->pending()->create();

        $this->actingAs($admin)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'published'])
            ->assertRedirect()->assertSessionHas('success', 'Review published.');
        $review->refresh();
        $this->assertSame(ReviewStatus::Published, $review->status);
        $this->assertSame($admin->id, $review->moderated_by);
        $this->assertNotNull($review->moderated_at);

        $this->actingAs($admin)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'rejected'])->assertSessionHas('success', 'Review rejected.');
        $this->assertSame(ReviewStatus::Rejected, $review->fresh()->status);

        $this->actingAs($admin)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'pending'])->assertSessionHas('success', 'Review put back in the queue.');
        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    #[Test]
    public function only_a_known_state_is_accepted(): void
    {
        $review = ProductReview::factory()->pending()->create();

        $this->actingAs($this->admin())->patch(route('acp.commerce.reviews.update', $review), ['status' => 'banana'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin())->patch(route('acp.commerce.reviews.update', $review), [])->assertSessionHasErrors('status');

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    #[Test]
    public function publishing_puts_a_review_in_the_shop_and_rejecting_takes_it_out_again(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced('5.00')->stocked(3)->create();
        $review = ProductReview::factory()->for($product)->pending()->rated(4)->create();
        $count = fn () => $this->get(route('shop.products.show', $product))->viewData('page')['props']['reviews']['summary']['count'];

        $this->assertSame(0, $count());

        $this->actingAs($admin)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'published']);
        $this->app['auth']->forgetGuards();
        $this->assertSame(1, $count());

        $this->actingAs($admin)->patch(route('acp.commerce.reviews.update', $review), ['status' => 'rejected']);
        $this->app['auth']->forgetGuards();
        $this->assertSame(0, $count());
    }

    #[Test]
    public function a_review_can_be_deleted(): void
    {
        $review = ProductReview::factory()->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.reviews.destroy', $review))
            ->assertRedirect()->assertSessionHas('success', 'Review deleted.');

        $this->assertDatabaseMissing('product_reviews', ['id' => $review->id]);
    }

    // --- The overview ------------------------------------------------------------------------------

    #[Test]
    public function the_commerce_overview_counts_what_is_waiting(): void
    {
        ProductReview::factory()->count(3)->pending()->create();
        ProductReview::factory()->count(2)->create();
        ProductReview::factory()->rejected()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.reviews', ['pending' => 3, 'published' => 2]));
    }
}
