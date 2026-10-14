<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\LicenceKey;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Makes the row only. It does not touch the stock count, which follows the pool: put keys in with
 * `App\Support\Commerce\Digital\LicenceKeys::add()` when a test needs the two to agree.
 *
 * @extends Factory<LicenceKey>
 */
class LicenceKeyFactory extends Factory
{
    protected $model = LicenceKey::class;

    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory()->keyed(),
            'value' => 'KEY-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
            // Worked out from the value just before saving.
            'fingerprint' => '',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LicenceKey $key) {
            if ($key->fingerprint === '') {
                $key->fingerprint = LicenceKey::fingerprintOf((string) $key->value);
            }
        });
    }

    public function issuedTo(OrderItem $item): static
    {
        return $this->state(['order_item_id' => $item->id, 'issued_at' => now()]);
    }
}
