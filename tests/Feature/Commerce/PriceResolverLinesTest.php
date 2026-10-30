<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pricing every line of a cart at once gives the answer pricing each line alone does (docs/query-budgets.md).
 */
class PriceResolverLinesTest extends TestCase
{
    use RefreshDatabase;

    private function line(Cart $cart, Product $product, ?ProductVariant $variant = null): CartItem
    {
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => 1,
            'unit_price' => '1.00',
            'total' => '1.00',
        ]);

        return $item->load(['product', 'variant']);
    }

    #[Test]
    public function every_line_gets_the_price_resolving_it_alone_gives(): void
    {
        config(['commerce.currency' => 'USD']);
        $cart = Cart::create(['currency' => 'USD', 'session_id' => str_repeat('s', 40)]);
        $resolver = new PriceResolver;

        $plain = Product::factory()->priced('10.00')->create();

        $cheapOnSale = Product::factory()->priced('30.00')->create();
        Price::factory()->for($cheapOnSale, 'priceable')->create(['amount' => '25.00']);

        $withOwnPrice = Product::factory()->priced('20.00')->create();
        $ownPrice = ProductVariant::factory()->for($withOwnPrice)->priced('35.00')->create();

        $withoutOwnPrice = ProductVariant::factory()->for($plain)->create();

        $inEuros = Product::factory()->priced('9.00', 'EUR')->create();
        $switchedOff = Product::factory()->create();
        Price::factory()->for($switchedOff, 'priceable')->create(['amount' => '5.00', 'is_active' => false]);

        $lines = [
            $this->line($cart, $plain),
            $this->line($cart, $cheapOnSale),
            $this->line($cart, $withOwnPrice, $ownPrice),
            $this->line($cart, $plain, $withoutOwnPrice),
            $this->line($cart, $inEuros),
            $this->line($cart, $switchedOff),
        ];

        $resolved = $resolver->resolveLines($lines);

        foreach ($lines as $line) {
            $alone = $resolver->resolve($line->product, $line->variant);

            $this->assertSame($alone?->id, $resolved[$line->id]?->id, "line {$line->id}");
        }

        $this->assertSame('25.00', $resolved[$lines[1]->id]->amount, 'the lowest of a product\'s prices');
        $this->assertSame('35.00', $resolved[$lines[2]->id]->amount, 'a variant\'s own price');
        $this->assertSame('10.00', $resolved[$lines[3]->id]->amount, 'a variant without one is sold at the product\'s');
        $this->assertNull($resolved[$lines[4]->id], 'a price in another currency cannot be charged');
        $this->assertNull($resolved[$lines[5]->id], 'nor one that is switched off');
    }

    #[Test]
    public function two_prices_alike_resolve_to_the_older_one_the_same_way_every_time(): void
    {
        config(['commerce.currency' => 'USD']);
        $product = Product::factory()->priced('12.00')->create();
        $second = Price::factory()->for($product, 'priceable')->create(['amount' => '12.00']);
        $cart = Cart::create(['currency' => 'USD', 'session_id' => str_repeat('s', 40)]);
        $line = $this->line($cart, $product);

        $resolver = new PriceResolver;
        $first = $product->prices()->orderBy('id')->first();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $resolver->resolve($product)->id);
        $this->assertSame($first->id, $resolver->resolveLines([$line])[$line->id]->id);
    }

    #[Test]
    public function the_prices_of_all_the_lines_cost_one_query(): void
    {
        config(['commerce.currency' => 'USD']);
        $cart = Cart::create(['currency' => 'USD', 'session_id' => str_repeat('s', 40)]);
        $lines = [];

        foreach (range(1, 6) as $unused) {
            $product = Product::factory()->priced('10.00')->create();
            $variant = ProductVariant::factory()->for($product)->priced('12.00')->create();
            $lines[] = $this->line($cart, $product, $variant);
            $lines[] = $this->line($cart, $product);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolved = (new PriceResolver)->resolveLines($lines);
        $asked = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $asked);
        $this->assertCount(12, array_filter($resolved));
    }

    #[Test]
    public function no_lines_ask_nothing(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame([], (new PriceResolver)->resolveLines([]));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }
}
