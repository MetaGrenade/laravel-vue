<?php

namespace Tests\Feature\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CouponManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.currency' => 'USD']);
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'code' => 'SAVE10',
            'description' => 'Spring sale',
            'type' => 'percent',
            'value' => '10',
            'is_active' => true,
        ], $overrides);
    }

    // --- Access ------------------------------------------------------------------------------------

    #[Test]
    public function the_pages_need_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.coupons.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.coupons.index'))->assertForbidden();
    }

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $coupon = Coupon::factory()->create();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        $this->actingAs($viewer)->get(route('acp.commerce.coupons.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.commerce.coupons.edit', $coupon))->assertOk();
        $this->actingAs($viewer)->get(route('acp.commerce.coupons.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.coupons.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.coupons.update', $coupon), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.coupons.destroy', $coupon))->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.edit');
        $this->actingAs($viewer)->put(route('acp.commerce.coupons.update', $coupon), $this->payload(['code' => 'NEWCODE']))->assertRedirect();
        $this->actingAs($viewer)->delete(route('acp.commerce.coupons.destroy', $coupon))->assertForbidden();
    }

    #[Test]
    public function the_shop_section_being_off_hides_the_pages(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.coupons.index'))->assertNotFound();
    }

    // --- The list ----------------------------------------------------------------------------------

    #[Test]
    public function the_list_shows_each_code_with_its_uses_and_status(): void
    {
        $live = Coupon::factory()->percent('10')->limited(total: 5)->create(['code' => 'LIVE']);
        Coupon::factory()->fixed('5.00')->expired()->create(['code' => 'OLD']);
        Coupon::factory()->freeShipping()->inactive()->create(['code' => 'OFF']);
        Coupon::factory()->create(['code' => 'ONCE'])->update(['max_redemptions' => 1]);
        Order::factory()->count(2)->create(['coupon_id' => $live->id, 'status' => 'processing']);
        Order::factory()->create(['coupon_id' => $live->id, 'status' => 'cancelled']);
        Order::factory()->create(['coupon_id' => Coupon::where('code', 'ONCE')->value('id'), 'status' => 'processing']);

        $rows = collect($this->actingAs($this->admin())->get(route('acp.commerce.coupons.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('acp/CommerceCoupons')->where('currency', 'USD'))
            ->viewData('page')['props']['coupons']['data'])->keyBy('code');

        $this->assertSame(['active', 2], [$rows['LIVE']['status'], $rows['LIVE']['uses']], 'a cancelled order does not count');
        $this->assertSame('expired', $rows['OLD']['status']);
        $this->assertSame('inactive', $rows['OFF']['status']);
        $this->assertSame('used_up', $rows['ONCE']['status']);
        $this->assertSame('10', $rows['LIVE']['value'], 'trailing zeros are dropped');
        $this->assertSame('5', $rows['OLD']['value']);
    }

    #[Test]
    public function the_list_can_be_searched_by_code_or_note(): void
    {
        Coupon::factory()->create(['code' => 'SPRING20', 'description' => null]);
        Coupon::factory()->create(['code' => 'OTHER', 'description' => 'Black Friday weekend']);
        Coupon::factory()->create(['code' => 'UNRELATED']);

        $admin = $this->admin();
        $codes = fn (string $search) => collect($this->actingAs($admin)->get(route('acp.commerce.coupons.index', ['search' => $search]))
            ->viewData('page')['props']['coupons']['data'])->pluck('code')->all();

        $this->assertSame(['SPRING20'], $codes('spring'));
        $this->assertSame(['OTHER'], $codes('black friday'));
    }

    #[Test]
    public function a_fixed_amount_in_another_currency_is_flagged(): void
    {
        Coupon::factory()->fixed('5.00', 'EUR')->create(['code' => 'EUROS']);

        $this->actingAs($this->admin())->get(route('acp.commerce.coupons.index'))->assertInertia(fn (Assert $page) => $page
            ->where('coupons.data.0.currency_mismatch', true));
    }

    // --- Creating and editing ----------------------------------------------------------------------

    #[Test]
    public function a_percentage_code_is_created(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload([
            'minimum_subtotal' => '25.00',
            'max_redemptions' => '100',
            'max_redemptions_per_customer' => '1',
        ]))->assertRedirect()->assertSessionHas('success');

        $coupon = Coupon::sole();

        $this->assertSame('SAVE10', $coupon->code);
        $this->assertSame(CouponType::Percent, $coupon->type);
        $this->assertSame('10.0000', $coupon->value);
        $this->assertNull($coupon->currency);
        $this->assertSame('25.00', $coupon->minimum_subtotal);
        $this->assertSame(100, $coupon->max_redemptions);
        $this->assertSame(1, $coupon->max_redemptions_per_customer);
        $this->assertTrue($coupon->is_active);
    }

    #[Test]
    public function creating_a_code_goes_on_to_its_page(): void
    {
        $response = $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload());

        $response->assertRedirect(route('acp.commerce.coupons.edit', Coupon::sole()));
    }

    #[Test]
    public function a_fixed_amount_is_in_the_shops_currency(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload(['type' => 'fixed', 'value' => '5.50']))->assertRedirect();

        $coupon = Coupon::sole();

        $this->assertSame(CouponType::Fixed, $coupon->type);
        $this->assertSame('5.5000', $coupon->value);
        $this->assertSame('USD', $coupon->currency);
    }

    #[Test]
    public function free_shipping_has_no_amount_and_no_limits_on_items(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload([
            'type' => 'free_shipping',
            'value' => '99',
            'product_ids' => [$product->id],
        ]))->assertRedirect();

        $coupon = Coupon::sole();

        $this->assertNull($coupon->value);
        $this->assertNull($coupon->currency);
        $this->assertSame(0, $coupon->products()->count());
    }

    #[Test]
    public function a_code_can_be_limited_to_products_and_categories(): void
    {
        $products = Product::factory()->count(2)->create();
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);

        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload([
            'product_ids' => $products->pluck('id')->all(),
            'category_ids' => [$category->id],
        ]))->assertRedirect();

        $coupon = Coupon::sole();

        $this->assertEqualsCanonicalizing($products->pluck('id')->all(), $coupon->products()->pluck('products.id')->all());
        $this->assertSame([$category->id], $coupon->categories()->pluck('product_categories.id')->all());
    }

    #[Test]
    public function the_code_is_stored_in_capitals_without_stray_spaces(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload(['code' => '  summer-2026 ']))->assertRedirect();

        $this->assertSame('SUMMER-2026', Coupon::sole()->code);
    }

    #[Test]
    public function the_times_a_browser_sends_are_kept_in_the_apps_timezone(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload([
            'starts_at' => '2026-12-01T09:00:00.000+02:00',
            'ends_at' => '2026-12-31T23:59:00.000-05:00',
        ]))->assertRedirect();

        $coupon = Coupon::sole();

        $this->assertSame('2026-12-01 07:00:00', $coupon->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-01 04:59:00', $coupon->ends_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function editing_a_code_saves_it_and_its_restrictions(): void
    {
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'OLD']);
        $keep = Product::factory()->create();
        $drop = Product::factory()->create();
        $coupon->products()->attach([$keep->id, $drop->id]);

        $this->actingAs($this->admin())->put(route('acp.commerce.coupons.update', $coupon), $this->payload([
            'code' => 'NEW',
            'type' => 'fixed',
            'value' => '3',
            'is_active' => false,
            'product_ids' => [$keep->id],
        ]))->assertRedirect()->assertSessionHas('success');

        $coupon->refresh();

        $this->assertSame('NEW', $coupon->code);
        $this->assertSame(CouponType::Fixed, $coupon->type);
        $this->assertSame('USD', $coupon->currency);
        $this->assertFalse($coupon->is_active);
        $this->assertSame([$keep->id], $coupon->products()->pluck('products.id')->all());
    }

    #[Test]
    public function switching_to_a_percentage_clears_the_currency(): void
    {
        $coupon = Coupon::factory()->fixed('5.00')->create();

        $this->actingAs($this->admin())->put(route('acp.commerce.coupons.update', $coupon), $this->payload(['value' => '15']))->assertRedirect();

        $this->assertNull($coupon->fresh()->currency);
    }

    #[Test]
    public function the_edit_page_shows_the_code_its_limits_and_what_it_has_done(): void
    {
        $product = Product::factory()->create(['name' => 'Hoodie']);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $coupon = Coupon::factory()->percent('12.5')->forProducts([$product])->forCategories([$category])->create(['code' => 'SAVE']);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'processing', 'discount_total' => '7.50']);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'processing', 'discount_total' => '2.25']);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'cancelled', 'discount_total' => '99.00']);

        $this->actingAs($this->admin())->get(route('acp.commerce.coupons.edit', $coupon))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/CommerceCouponEdit')
            ->where('coupon.code', 'SAVE')
            ->where('coupon.value', '12.5')
            ->where('coupon.category_ids', [$category->id])
            ->where('coupon.products', [['id' => $product->id, 'name' => 'Hoodie']])
            ->where('usage', ['orders' => 2, 'discounted' => '9.75'])
            ->has('categories', 1));
    }

    // --- Validation --------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): array
    {
        return [
            'no code' => [['code' => ''], 'code'],
            'a code with spaces' => [['code' => 'TWO WORDS'], 'code'],
            'a code with symbols' => [['code' => 'SAVE%'], 'code'],
            'a code that is too long' => [['code' => str_repeat('A', 41)], 'code'],
            'an unknown type' => [['type' => 'bogus'], 'type'],
            'no amount' => [['value' => ''], 'value'],
            'a percentage of nothing' => [['value' => '0'], 'value'],
            'a percentage over 100' => [['value' => '100.5'], 'value'],
            'a percentage with five decimals' => [['value' => '10.12345'], 'value'],
            'a negative amount' => [['type' => 'fixed', 'value' => '-5'], 'value'],
            'an amount with three decimals' => [['type' => 'fixed', 'value' => '5.123'], 'value'],
            'not a number' => [['value' => 'ten'], 'value'],
            'a negative minimum spend' => [['minimum_subtotal' => '-1'], 'minimum_subtotal'],
            'ending before it starts' => [['starts_at' => '2026-12-02T00:00:00Z', 'ends_at' => '2026-12-01T00:00:00Z'], 'ends_at'],
            'no uses allowed' => [['max_redemptions' => '0'], 'max_redemptions'],
            'a fraction of a use' => [['max_redemptions_per_customer' => '1.5'], 'max_redemptions_per_customer'],
            'a product that does not exist' => [['product_ids' => [999999]], 'product_ids.0'],
            'a category that does not exist' => [['category_ids' => [999999]], 'category_ids.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalid')]
    public function bad_input_is_refused(array $overrides, string $field): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Coupon::query()->count());
    }

    #[Test]
    public function a_code_must_be_unique_whatever_its_case(): void
    {
        Coupon::factory()->create(['code' => 'SAVE10']);

        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload(['code' => 'save10']))
            ->assertSessionHasErrors(['code' => 'There is already a code like this.']);
    }

    #[Test]
    public function a_code_can_be_saved_again_under_its_own_name(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'SAVE10']);

        $this->actingAs($this->admin())->put(route('acp.commerce.coupons.update', $coupon), $this->payload(['code' => 'save10', 'value' => '20']))
            ->assertSessionHasNoErrors();

        $this->assertSame('20.0000', $coupon->fresh()->value);
    }

    #[Test]
    public function free_shipping_does_not_need_an_amount(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.coupons.store'), $this->payload(['type' => 'free_shipping', 'value' => '']))
            ->assertSessionHasNoErrors();
    }

    // --- Deleting ----------------------------------------------------------------------------------

    #[Test]
    public function an_unused_code_can_be_deleted(): void
    {
        $coupon = Coupon::factory()->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.coupons.destroy', $coupon))
            ->assertRedirect(route('acp.commerce.coupons.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($coupon);
    }

    #[Test]
    public function a_code_that_orders_used_cannot_be_deleted_even_when_they_are_cancelled(): void
    {
        $coupon = Coupon::factory()->create();
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'cancelled']);

        $this->actingAs($this->admin())->delete(route('acp.commerce.coupons.destroy', $coupon))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertModelExists($coupon);
    }

    #[Test]
    public function the_database_itself_refuses_to_delete_a_used_code(): void
    {
        $coupon = Coupon::factory()->create();
        Order::factory()->create(['coupon_id' => $coupon->id]);

        $this->expectException(QueryException::class);

        $coupon->delete();
    }

    // --- Picking products --------------------------------------------------------------------------

    #[Test]
    public function products_can_be_found_by_name(): void
    {
        Product::factory()->create(['name' => 'Blue Hoodie']);
        Product::factory()->create(['name' => 'Red Hoodie']);
        Product::factory()->create(['name' => 'Mug']);

        $names = $this->actingAs($this->admin())->getJson(route('acp.commerce.coupons.product-search', ['search' => 'hoodie']))
            ->assertOk()->json('data.*.name');

        $this->assertSame(['Blue Hoodie', 'Red Hoodie'], $names);
    }

    #[Test]
    public function the_product_search_returns_only_a_few_and_needs_access(): void
    {
        Product::factory()->count(20)->create();

        $this->actingAs($this->admin())->getJson(route('acp.commerce.coupons.product-search'))->assertOk()->assertJsonCount(15, 'data');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->getJson(route('acp.commerce.coupons.product-search'))->assertForbidden();
    }
}
