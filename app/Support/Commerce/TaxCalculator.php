<?php

namespace App\Support\Commerce;

use App\Models\TaxRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Works out the tax on an order from the tax table.
 *
 * Prices are tax-exclusive. For a destination, every active rate for that
 * country applies (a rate with a region only when the address is in that region),
 * and several rates add together (for example a federal and a provincial tax).
 * Rates for "*" are the fallback for countries and regions with no rate of their
 * own. Each rate is applied to the taxable items, and to shipping when the rate
 * says so, and rounded half up, so the total is exact in minor units.
 */
class TaxCalculator
{
    /**
     * The rates that apply to a destination.
     *
     * @return Collection<int, TaxRate>
     */
    public function ratesFor(?string $country, ?string $region): Collection
    {
        if ($country === null || $country === '') {
            return collect();
        }

        $country = strtoupper($country);
        $rates = TaxRate::query()->active()->whereIn('country', [$country, Countries::ANY])->orderBy('id')->get();

        $own = $rates->filter(fn (TaxRate $rate) => $rate->country === $country
            && ($rate->region === null || $this->sameRegion($rate->region, $region)));

        return ($own->isNotEmpty() ? $own : $rates->where('country', Countries::ANY))->values();
    }

    /**
     * Whether tax in this country depends on the region, so the customer must give one.
     */
    public function needsRegion(?string $country): bool
    {
        if ($country === null || $country === '') {
            return false;
        }

        return TaxRate::query()->active()
            ->where('country', strtoupper($country))
            ->whereNotNull('region')
            ->exists();
    }

    /**
     * @param  Collection<int, TaxRate>  $rates
     */
    public function calculate(Collection $rates, Money $taxableItems, Money $shipping): TaxResult
    {
        $lines = [];
        $itemTax = Money::zero($taxableItems->currency);
        $shippingTax = Money::zero($taxableItems->currency);

        foreach ($rates as $rate) {
            $onItems = $taxableItems->percent((string) $rate->rate);
            $onShipping = $rate->applies_to_shipping ? $shipping->percent((string) $rate->rate) : Money::zero($taxableItems->currency);

            $itemTax = $itemTax->add($onItems);
            $shippingTax = $shippingTax->add($onShipping);

            $lines[] = new TaxLine(
                name: $rate->name,
                rate: $this->trimRate((string) $rate->rate),
                amount: $onItems->add($onShipping),
            );
        }

        return new TaxResult($lines, $itemTax, $shippingTax);
    }

    private function sameRegion(string $configured, ?string $given): bool
    {
        return $given !== null && Str::lower(trim($configured)) === Str::lower(trim($given));
    }

    /**
     * "20.0000" reads better as "20" and "8.8750" as "8.875".
     */
    private function trimRate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
