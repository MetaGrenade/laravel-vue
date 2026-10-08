<?php

namespace Tests\Feature\Commerce;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Support\Commerce\Money;
use App\Support\Commerce\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calculator(): ShippingCalculator
    {
        return app(ShippingCalculator::class);
    }

    private function usd(string $amount): Money
    {
        return Money::parse($amount, 'USD');
    }

    #[Test]
    public function without_any_zone_shipping_is_not_configured_and_serves_everywhere(): void
    {
        $this->assertFalse($this->calculator()->isConfigured());
        $this->assertNull($this->calculator()->servedCountries());
        $this->assertNull($this->calculator()->zoneFor('GB'));
    }

    #[Test]
    public function inactive_zones_do_not_count_as_configured(): void
    {
        ShippingZone::factory()->inactive()->serving(['GB'])->withRate('Standard', '5.00')->create();

        $this->assertFalse($this->calculator()->isConfigured());
    }

    #[Test]
    public function the_served_countries_are_the_union_of_active_zones(): void
    {
        ShippingZone::factory()->serving(['GB', 'IE'])->create();
        ShippingZone::factory()->serving(['DE', 'gb'])->create();
        ShippingZone::factory()->inactive()->serving(['FR'])->create();

        $this->assertSame(['DE', 'GB', 'IE'], $this->calculator()->servedCountries());
    }

    #[Test]
    public function a_rest_of_world_zone_means_everywhere(): void
    {
        ShippingZone::factory()->serving(['GB'])->create();
        ShippingZone::factory()->everywhereElse()->create();

        $this->assertNull($this->calculator()->servedCountries());
    }

    #[Test]
    public function a_zone_that_names_the_country_beats_the_rest_of_world_zone(): void
    {
        $world = ShippingZone::factory()->everywhereElse()->create(['position' => 0]);
        $uk = ShippingZone::factory()->serving(['GB'])->create(['position' => 5]);

        $this->assertTrue($this->calculator()->zoneFor('GB')->is($uk), 'position does not outrank a named country');
        $this->assertTrue($this->calculator()->zoneFor('JP')->is($world));
    }

    #[Test]
    public function among_zones_naming_a_country_the_first_by_position_wins(): void
    {
        $second = ShippingZone::factory()->serving(['GB'])->create(['position' => 2]);
        $first = ShippingZone::factory()->serving(['GB'])->create(['position' => 1]);

        $this->assertTrue($this->calculator()->zoneFor('gb')->is($first));
        $this->assertFalse($this->calculator()->zoneFor('GB')->is($second));
    }

    #[Test]
    public function a_country_nobody_serves_has_no_zone(): void
    {
        ShippingZone::factory()->serving(['GB'])->create();

        $this->assertNull($this->calculator()->zoneFor('JP'));
        $this->assertSame([], $this->calculator()->optionsFor('JP', $this->usd('50.00'))->all());
    }

    #[Test]
    public function rates_are_offered_in_order_and_inactive_ones_are_hidden(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Express', 'amount' => '15.00', 'position' => 2]);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 1]);
        ShippingRate::factory()->for($zone, 'zone')->inactive()->create(['name' => 'Retired', 'position' => 0]);

        $options = $this->calculator()->optionsFor('GB', $this->usd('20.00'));

        $this->assertSame(['Standard', 'Express'], $options->pluck('name')->all());
        $this->assertSame(['5.00', '15.00'], $options->map(fn ($option) => $option->amount->toDecimal())->all());
    }

    #[Test]
    public function subtotal_limits_decide_which_rates_are_offered(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Small order', 'amount' => '8.00', 'max_subtotal' => '49.99', 'position' => 1]);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'min_subtotal' => '50.00', 'max_subtotal' => '99.99', 'position' => 2]);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Free over 100', 'amount' => '0.00', 'min_subtotal' => '100.00', 'position' => 3]);

        $names = fn (string $subtotal) => $this->calculator()->optionsFor('GB', $this->usd($subtotal))->pluck('name')->all();

        $this->assertSame(['Small order'], $names('10.00'));
        $this->assertSame(['Small order'], $names('49.99'));
        $this->assertSame(['Standard'], $names('50.00'), 'the minimum is inclusive');
        $this->assertSame(['Standard'], $names('99.99'));
        $this->assertSame(['Free over 100'], $names('100.00'));
        $this->assertSame(['Free over 100'], $names('5000.00'));
    }

    #[Test]
    public function a_zone_with_no_applicable_rate_offers_nothing_rather_than_falling_through(): void
    {
        $uk = ShippingZone::factory()->serving(['GB'])->create(['position' => 1]);
        ShippingRate::factory()->for($uk, 'zone')->create(['min_subtotal' => '100.00']);
        ShippingZone::factory()->everywhereElse()->withRate('International', '30.00')->create(['position' => 2]);

        $this->assertSame([], $this->calculator()->optionsFor('GB', $this->usd('20.00'))->all(), 'the UK zone decides, even when none of its rates fit');
    }
}
