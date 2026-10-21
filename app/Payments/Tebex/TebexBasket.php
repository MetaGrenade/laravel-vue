<?php

namespace App\Payments\Tebex;

use App\Support\Commerce\Money;

/**
 * A basket as Tebex has it: what is in it, what Tebex says it costs, and where to pay.
 *
 * The prices are what Tebex quotes: the tax is the one Tebex works out for the address it was given, and what the buyer
 * really pays is decided at its checkout (it applies its own coupons, creator codes and gift cards).
 */
final readonly class TebexBasket
{
    /**
     * @param  list<array{id: int, name: string, quantity: int, price: string}>  $packages
     * @param  array<string, mixed>  $custom
     */
    public function __construct(
        public string $ident,
        public bool $complete,
        public string $currency,
        public Money $basePrice,
        public Money $salesTax,
        public Money $totalPrice,
        public ?string $checkoutUrl,
        public ?string $paymentUrl,
        public array $packages,
        public array $custom,
        public ?int $usernameId = null,
        public ?string $username = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The `data` object of a basket endpoint.
     */
    public static function fromApi(array $data): self
    {
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $currency = strlen($currency) === 3 ? $currency : 'USD';

        $packages = [];

        foreach ((array) ($data['packages'] ?? []) as $package) {
            if (! is_array($package)) {
                continue;
            }

            $inBasket = (array) ($package['in_basket'] ?? []);

            $packages[] = [
                'id' => (int) ($package['id'] ?? 0),
                'name' => (string) ($package['name'] ?? ''),
                'quantity' => (int) ($inBasket['quantity'] ?? 0),
                'price' => (string) ($inBasket['price'] ?? '0'),
            ];
        }

        $links = (array) ($data['links'] ?? []);

        return new self(
            ident: (string) $data['ident'],
            complete: (bool) ($data['complete'] ?? false),
            currency: $currency,
            basePrice: Money::parse($data['base_price'] ?? 0, $currency),
            salesTax: Money::parse($data['sales_tax'] ?? 0, $currency),
            totalPrice: Money::parse($data['total_price'] ?? 0, $currency),
            checkoutUrl: is_string($links['checkout'] ?? null) && $links['checkout'] !== '' ? $links['checkout'] : null,
            paymentUrl: is_string($links['payment'] ?? null) && $links['payment'] !== '' ? $links['payment'] : null,
            packages: $packages,
            custom: is_array($data['custom'] ?? null) ? $data['custom'] : [],
            // Tebex says 0 for a basket nobody has signed in to.
            usernameId: (int) ($data['username_id'] ?? 0) > 0 ? (int) $data['username_id'] : null,
            username: is_string($data['username'] ?? null) && $data['username'] !== '' ? $data['username'] : null,
        );
    }

    /**
     * Whether the basket belongs to someone Tebex knows: a username it recognised, or an account the buyer signed in
     * with. A store that identifies its buyers will not take a package into a basket until it does.
     */
    public function isAuthorised(): bool
    {
        return $this->usernameId !== null;
    }

    /**
     * Whether Tebex has been paid for it: it stops offering a way to pay and offers a payment instead.
     */
    public function isPaid(): bool
    {
        return $this->complete || $this->paymentUrl !== null;
    }
}
