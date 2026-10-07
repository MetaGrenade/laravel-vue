<?php

namespace App\Support\Commerce;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Support\Collection;

/**
 * Decides where the shop ships and which methods an order can choose.
 *
 * If no active shipping zone exists, shipping has not been set up: orders are
 * accepted for any country at no shipping charge (the merchant arranges delivery
 * themselves). Once a zone exists, only the countries the zones cover can order
 * physical goods.
 */
class ShippingCalculator
{
    public function isConfigured(): bool
    {
        return ShippingZone::query()->active()->exists();
    }

    /**
     * The countries physical goods can be shipped to, or null for anywhere.
     *
     * @return list<string>|null
     */
    public function servedCountries(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $zones = ShippingZone::query()->active()->get();

        if ($zones->contains(fn (ShippingZone $zone) => $zone->isCatchAll())) {
            return null;
        }

        return $zones
            ->flatMap(fn (ShippingZone $zone) => $zone->countries ?? [])
            ->map(fn (string $country) => strtoupper($country))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The zone that handles a country: a zone that names the country beats the
     * "rest of world" zone, and among zones that name it the first by position wins.
     */
    public function zoneFor(string $country): ?ShippingZone
    {
        $zones = ShippingZone::query()->active()->orderBy('position')->orderBy('id')->get();

        return $zones->first(fn (ShippingZone $zone) => $zone->listsCountry($country))
            ?? $zones->first(fn (ShippingZone $zone) => $zone->isCatchAll());
    }

    /**
     * The shipping methods an order can choose, in display order: the zone's
     * active rates whose subtotal limits the shippable items satisfy.
     *
     * @return Collection<int, ShippingOption>
     */
    public function optionsFor(string $country, Money $shippableSubtotal): Collection
    {
        $zone = $this->zoneFor($country);

        if ($zone === null) {
            return collect();
        }

        return $zone->rates()
            ->where('is_active', true)
            ->get()
            ->filter(fn (ShippingRate $rate) => $rate->appliesTo($shippableSubtotal))
            ->map(fn (ShippingRate $rate) => ShippingOption::fromRate($rate, $shippableSubtotal->currency))
            ->values();
    }
}
