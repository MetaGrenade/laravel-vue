<?php

namespace Database\Factories;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Support\Commerce\GiftCards\GiftCardCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes the row only: no ledger entry, and a code nobody knows. Use `code()` when a test has to type it in, and
 * `App\Support\Commerce\GiftCards\GiftCards::issue()` when the card and its history have to agree.
 *
 * @extends Factory<GiftCard>
 */
class GiftCardFactory extends Factory
{
    protected $model = GiftCard::class;

    public function definition(): array
    {
        $code = GiftCardCode::normalise(GiftCardCode::generate());

        return [
            'code_hash' => GiftCardCode::hash($code),
            'last_four' => GiftCardCode::lastFour($code),
            'currency' => strtoupper((string) config('commerce.currency', 'USD')),
            'initial_amount' => '50.00',
            'balance' => '50.00',
            'is_active' => true,
        ];
    }

    /**
     * A card with this code (any case, with or without dashes). It must be made of the characters codes use.
     */
    public function code(string $code): static
    {
        $normalised = GiftCardCode::normalise($code);

        return $this->state([
            'code_hash' => GiftCardCode::hash($normalised),
            'last_four' => GiftCardCode::lastFour($normalised),
        ]);
    }

    /**
     * A card issued for this much, with all of it still on it.
     */
    public function worth(string $amount): static
    {
        return $this->state(['initial_amount' => $amount, 'balance' => $amount]);
    }

    /**
     * Also write the entry that says where the balance came from, so that the card and its history agree. (The
     * balance in force when the card is made is what the entry says it was issued for.)
     */
    public function withHistory(): static
    {
        return $this->afterCreating(function (GiftCard $card) {
            GiftCardTransaction::query()->create([
                'gift_card_id' => $card->id,
                'type' => GiftCardTransaction::ISSUED,
                'amount' => $card->balance,
                'balance_after' => $card->balance,
                'note' => 'Made by the factory',
            ]);
        });
    }

    public function spent(): static
    {
        return $this->state(['balance' => '0.00']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function expiring(string $when = '+1 month'): static
    {
        return $this->state(['expires_at' => now()->modify($when)]);
    }
}
