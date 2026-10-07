<?php

namespace Tests\Feature\Commerce;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Support\Commerce\Money;
use App\Support\Commerce\ShippingCalculator;
use Database\Seeders\CommerceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A fresh install seeded with the demo data should be able to try the whole
 * checkout, and seeding twice must not pile up duplicates.
 */
class CommerceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_shipping_and_tax_and_is_safe_to_run_again(): void
    {
        $this->seed(CommerceDemoSeeder::class);
        $counts = [ShippingZone::count(), ShippingRate::count(), TaxRate::count()];

        $this->seed(CommerceDemoSeeder::class);

        $this->assertSame([3, 5, 2], $counts);
        $this->assertSame($counts, [ShippingZone::count(), ShippingRate::count(), TaxRate::count()]);
    }

    #[Test]
    public function the_demo_shipping_behaves_as_described(): void
    {
        $this->seed(CommerceDemoSeeder::class);
        $calculator = app(ShippingCalculator::class);
        $names = fn (string $country, string $subtotal) => $calculator->optionsFor($country, Money::parse($subtotal, 'USD'))->pluck('name')->all();

        $this->assertSame(['Standard', 'Express'], $names('US', '50.00'));
        $this->assertSame(['Free standard shipping', 'Express'], $names('US', '100.00'), 'free standard shipping from 100');
        $this->assertSame(['Standard'], $names('GB', '50.00'));
        $this->assertSame(['International'], $names('JP', '50.00'), 'everywhere else falls into the rest-of-world zone');
        $this->assertNull($calculator->servedCountries(), 'the rest-of-world zone means anywhere');
    }
}
