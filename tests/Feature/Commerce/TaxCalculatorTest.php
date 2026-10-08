<?php

namespace Tests\Feature\Commerce;

use App\Models\TaxRate;
use App\Support\Commerce\Money;
use App\Support\Commerce\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calculator(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    private function usd(string $amount): Money
    {
        return Money::parse($amount, 'USD');
    }

    /**
     * @return list<string>
     */
    private function names(?string $country, ?string $region = null): array
    {
        return $this->calculator()->ratesFor($country, $region)->pluck('name')->all();
    }

    #[Test]
    public function a_country_with_no_rate_pays_no_tax(): void
    {
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB']);

        $this->assertSame([], $this->names('JP'));
        $this->assertSame([], $this->names(null));
        $this->assertSame([], $this->names(''));
    }

    #[Test]
    public function rates_match_by_country_regardless_of_case(): void
    {
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB']);

        $this->assertSame(['VAT'], $this->names('gb'));
    }

    #[Test]
    public function inactive_rates_are_ignored(): void
    {
        TaxRate::factory()->inactive()->create(['name' => 'Old VAT', 'country' => 'GB']);

        $this->assertSame([], $this->names('GB'));
    }

    #[Test]
    public function the_rest_of_world_rate_is_only_a_fallback(): void
    {
        TaxRate::factory()->create(['name' => 'Global', 'country' => '*', 'rate' => '5']);
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB']);

        $this->assertSame(['VAT'], $this->names('GB'), 'a country with its own rate does not also pay the fallback');
        $this->assertSame(['Global'], $this->names('JP'));
    }

    #[Test]
    public function a_regional_rate_applies_only_to_that_region_case_insensitively(): void
    {
        TaxRate::factory()->create(['name' => 'CA sales tax', 'country' => 'US', 'region' => 'California']);

        $this->assertSame(['CA sales tax'], $this->names('US', 'california'));
        $this->assertSame(['CA sales tax'], $this->names('US', '  California '));
        $this->assertSame([], $this->names('US', 'Oregon'), 'a region without a rate pays none');
        $this->assertSame([], $this->names('US', null));
    }

    #[Test]
    public function a_national_and_a_regional_rate_add_together(): void
    {
        TaxRate::factory()->create(['name' => 'GST', 'country' => 'CA', 'rate' => '5']);
        TaxRate::factory()->create(['name' => 'PST', 'country' => 'CA', 'region' => 'BC', 'rate' => '7']);

        $this->assertSame(['GST', 'PST'], $this->names('CA', 'BC'));
        $this->assertSame(['GST'], $this->names('CA', 'Ontario'));
    }

    #[Test]
    public function the_region_is_needed_only_where_a_rate_depends_on_it(): void
    {
        TaxRate::factory()->create(['country' => 'US', 'region' => 'California']);
        TaxRate::factory()->create(['country' => 'GB']);
        TaxRate::factory()->inactive()->create(['country' => 'DE', 'region' => 'Bavaria']);

        $this->assertTrue($this->calculator()->needsRegion('US'));
        $this->assertTrue($this->calculator()->needsRegion('us'));
        $this->assertFalse($this->calculator()->needsRegion('GB'));
        $this->assertFalse($this->calculator()->needsRegion('DE'), 'an inactive regional rate does not count');
        $this->assertFalse($this->calculator()->needsRegion(null));
    }

    #[Test]
    public function tax_is_charged_on_items_and_on_shipping(): void
    {
        $rates = TaxRate::factory()->count(1)->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20'])->all();

        $result = $this->calculator()->calculate(collect($rates), $this->usd('100.00'), $this->usd('5.00'));

        $this->assertSame('20.00', $result->onItems->toDecimal());
        $this->assertSame('1.00', $result->onShipping->toDecimal());
        $this->assertSame('21.00', $result->total()->toDecimal());
        $this->assertCount(1, $result->lines);
        $this->assertSame('VAT', $result->lines[0]->name);
        $this->assertSame('20', $result->lines[0]->rate);
        $this->assertSame('21.00', $result->lines[0]->amount->toDecimal());
    }

    #[Test]
    public function a_rate_can_leave_shipping_untaxed(): void
    {
        $rate = TaxRate::factory()->create(['country' => 'GB', 'rate' => '20', 'applies_to_shipping' => false]);

        $result = $this->calculator()->calculate(collect([$rate]), $this->usd('100.00'), $this->usd('5.00'));

        $this->assertSame('20.00', $result->total()->toDecimal());
        $this->assertTrue($result->onShipping->isZero());
    }

    #[Test]
    public function each_rate_rounds_half_up_to_the_cent(): void
    {
        $rate = TaxRate::factory()->create(['country' => 'US', 'rate' => '8.875']);

        // 10.00 x 8.875% = 0.8875 -> 0.89
        $this->assertSame('0.89', $this->calculator()->calculate(collect([$rate]), $this->usd('10.00'), $this->usd('0'))->total()->toDecimal());
        // 19.99 x 8.875% = 1.7741... -> 1.77
        $this->assertSame('1.77', $this->calculator()->calculate(collect([$rate]), $this->usd('19.99'), $this->usd('0'))->total()->toDecimal());
    }

    #[Test]
    public function several_rates_each_get_their_own_line_and_the_total_is_their_sum(): void
    {
        $rates = [
            TaxRate::factory()->create(['name' => 'GST', 'country' => 'CA', 'rate' => '5']),
            TaxRate::factory()->create(['name' => 'PST', 'country' => 'CA', 'region' => 'BC', 'rate' => '7']),
        ];

        $result = $this->calculator()->calculate(collect($rates), $this->usd('100.00'), $this->usd('10.00'));

        $this->assertSame(['5.50', '7.70'], array_map(fn ($line) => $line->amount->toDecimal(), $result->lines));
        $this->assertSame('13.20', $result->total()->toDecimal());
    }

    #[Test]
    public function no_rates_means_no_tax(): void
    {
        $result = $this->calculator()->calculate(collect(), $this->usd('100.00'), $this->usd('5.00'));

        $this->assertSame([], $result->lines);
        $this->assertTrue($result->total()->isZero());
    }
}
