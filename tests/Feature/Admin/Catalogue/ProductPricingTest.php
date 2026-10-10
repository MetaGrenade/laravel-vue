<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\PriceResolver;
use App\Support\Commerce\ProductAvailability;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    #[Test]
    public function a_product_is_priced_in_the_shops_currency(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.prices.store', $product), [
            'amount' => '25.5',
            'compare_at_amount' => '30',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $price = $product->prices()->sole();
        $this->assertSame('25.50', $price->amount);
        $this->assertSame('30.00', $price->compare_at_amount);
        $this->assertSame('USD', $price->currency, 'the shop sells in one currency; it is not chosen per price');
        $this->assertTrue($price->is_active);
        $this->assertSame($price->id, app(PriceResolver::class)->resolve($product)->id, 'and it is what a shopper is charged');
    }

    #[Test]
    public function a_variant_is_priced_separately_from_its_product(): void
    {
        $product = Product::factory()->priced('20.00')->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.prices.store', $variant), ['amount' => '35'])->assertSessionHasNoErrors();

        $this->assertSame('35.00', app(PriceResolver::class)->resolve($product, $variant)->amount);
        $this->assertSame('20.00', app(PriceResolver::class)->resolve($product)->amount);
        $this->assertSame(1, $product->prices()->count());
    }

    #[Test]
    public function the_amount_must_be_a_real_price(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        foreach (['', 'abc', '-5', '5.123', '1,50', '1000000000'] as $amount) {
            $this->actingAs($admin)->post(route('acp.commerce.prices.store', $product), ['amount' => $amount])
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, Price::count());
    }

    #[Test]
    public function a_price_of_zero_makes_a_free_product(): void
    {
        $product = Product::factory()->create();

        foreach (['0', '0.00'] as $amount) {
            $this->actingAs($this->admin())->post(route('acp.commerce.prices.store', $product), ['amount' => $amount, 'is_active' => false])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Price::count());
        $this->assertSame(['0.00', '0.00'], Price::query()->pluck('amount')->all());
    }

    #[Test]
    public function a_free_product_can_be_bought_and_a_negative_price_cannot_be_saved(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.prices.store', $product), ['amount' => '0'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '-0.01', 'is_active' => false])->assertSessionHasErrors('amount');

        $this->assertSame('0.00', app(PriceResolver::class)->resolve($product)->amount);
        $this->assertTrue(app(ProductAvailability::class)->canBuy($product->load(['prices' => ProductAvailability::chargeablePrices(), 'variants'])));
    }

    #[Test]
    public function the_original_price_must_be_higher_than_the_price(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.prices.store', $product), ['amount' => '20', 'compare_at_amount' => '20'])
            ->assertSessionHasErrors('compare_at_amount');
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '20', 'compare_at_amount' => '15'])
            ->assertSessionHasErrors('compare_at_amount');
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '20', 'compare_at_amount' => 'x'])
            ->assertSessionHasErrors('compare_at_amount');
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '20', 'compare_at_amount' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($product->prices()->sole()->compare_at_amount);
    }

    #[Test]
    public function only_one_price_can_be_active_at_a_time(): void
    {
        $product = Product::factory()->priced('20.00')->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.prices.store', $product), ['amount' => '18'])
            ->assertSessionHasErrors(['is_active' => 'There is already an active price. Change that one, or switch it off first.']);

        // A switched-off price is a draft: it can be added, and is not charged.
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '18', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame('20.00', app(PriceResolver::class)->resolve($product)->amount);

        // Switching it on while the other is on clashes; swapping them is done in two steps.
        $draft = $product->prices()->where('is_active', false)->first();
        $this->put(route('acp.commerce.prices.update', $draft), ['amount' => '18', 'is_active' => true])->assertSessionHasErrors('is_active');

        $live = $product->prices()->where('is_active', true)->first();
        $this->put(route('acp.commerce.prices.update', $live), ['amount' => '20', 'is_active' => false])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.prices.update', $draft), ['amount' => '18', 'is_active' => true])->assertSessionHasNoErrors();

        $this->assertSame('18.00', app(PriceResolver::class)->resolve($product)->amount);
    }

    #[Test]
    public function a_price_can_be_edited_without_clashing_with_itself(): void
    {
        $product = Product::factory()->priced('20.00')->create();
        $price = $product->prices()->first();

        $this->actingAs($this->admin())->put(route('acp.commerce.prices.update', $price), ['amount' => '22.5', 'compare_at_amount' => '30'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $price->refresh();
        $this->assertSame('22.50', $price->amount);
        $this->assertSame('30.00', $price->compare_at_amount);
        $this->assertTrue($price->is_active, 'left as it was when the form does not say');
    }

    #[Test]
    public function an_old_price_in_another_currency_keeps_its_currency_and_is_never_charged(): void
    {
        $product = Product::factory()->priced('9.00', 'EUR')->create();
        $price = $product->prices()->first();

        $this->actingAs($this->admin())->put(route('acp.commerce.prices.update', $price), ['amount' => '11'])->assertSessionHasNoErrors();

        $this->assertSame('EUR', $price->fresh()->currency);
        $this->assertNull(app(PriceResolver::class)->resolve($product), 'a euro price cannot be charged by a dollar shop');

        // It does not block a dollar price, because they are different currencies.
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '12'])->assertSessionHasNoErrors();
        $this->assertSame('12.00', app(PriceResolver::class)->resolve($product)->amount);
    }

    #[Test]
    public function a_zero_decimal_currency_takes_whole_amounts_only(): void
    {
        config(['commerce.currency' => 'JPY']);
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.prices.store', $product), ['amount' => '500.50'])
            ->assertSessionHasErrors(['amount' => 'JPY has no cents; enter a whole amount.']);

        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '500'])->assertSessionHasNoErrors();
        $this->assertSame('500.00', $product->prices()->sole()->amount);
        $this->assertSame('JPY', $product->prices()->sole()->currency);
    }

    #[Test]
    public function a_price_can_be_deleted(): void
    {
        $product = Product::factory()->priced('20.00')->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.prices.destroy', $product->prices()->first()))->assertSessionHas('success');

        $this->assertSame(0, Price::count());
        $this->assertNull(app(PriceResolver::class)->resolve($product), 'it cannot be bought until priced again');
    }

    #[Test]
    public function pricing_needs_the_matching_permissions(): void
    {
        $product = Product::factory()->priced('20.00')->create();
        $price = $product->prices()->first();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo(['commerce.acp.view', 'commerce.acp.edit']);

        $this->actingAs($viewer)->put(route('acp.commerce.prices.update', $price), ['amount' => '21'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.variants.prices.store', ProductVariant::factory()->for($product)->create()), ['amount' => '5'])->assertForbidden();
        $this->post(route('acp.commerce.prices.store', $product), ['amount' => '5', 'is_active' => false])->assertForbidden();
        $this->delete(route('acp.commerce.prices.destroy', $price))->assertForbidden();

        $this->assertSame('21.00', $price->fresh()->amount);
    }
}
