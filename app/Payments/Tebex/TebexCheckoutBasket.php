<?php

namespace App\Payments\Tebex;

use App\Support\Commerce\Money;

/**
 * A basket made through the Checkout API, as Tebex has it: what it holds, what Tebex says it costs, and where to pay.
 *
 * The Checkout API names no currency (prices are in the store's own, which the shop has to know), so the shop's is used to
 * read the amounts. The tax is the one Tebex works out for the buyer, and what they really pay is decided when they pay.
 */
final readonly class TebexCheckoutBasket
{
    /**
     * @param  list<array{id: int, quantity: int, price: string, line: int|null}>  $rows
     * @param  array<string, mixed>  $custom
     */
    public function __construct(
        public string $ident,
        public bool $complete,
        public Money $subTotal,
        public Money $tax,
        public Money $total,
        public ?string $checkoutUrl,
        public ?string $paymentUrl,
        public array $rows,
        public array $custom,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The basket, as the Checkout API answers (not wrapped in anything).
     * @param  string  $currency  The store's currency, which Tebex does not say.
     */
    public static function fromApi(array $data, string $currency): self
    {
        $details = (array) ($data['priceDetails'] ?? []);
        $links = (array) ($data['links'] ?? []);
        $rows = [];

        foreach ((array) ($data['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $custom = is_array($row['custom'] ?? null) ? $row['custom'] : [];
            $line = $custom['line'] ?? null;

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'price' => (string) ($row['price'] ?? '0'),
                'line' => is_int($line) || (is_string($line) && ctype_digit($line)) ? (int) $line : null,
            ];
        }

        $price = $data['price'] ?? 0;

        return new self(
            ident: (string) $data['ident'],
            complete: (bool) ($data['complete'] ?? false),
            subTotal: Money::parse($details['subTotal'] ?? $price, $currency),
            tax: Money::parse($details['tax'] ?? $data['tax'] ?? 0, $currency),
            total: Money::parse($details['total'] ?? $price, $currency),
            checkoutUrl: is_string($links['checkout'] ?? null) && $links['checkout'] !== '' ? $links['checkout'] : null,
            paymentUrl: is_string($links['payment'] ?? null) && $links['payment'] !== '' ? $links['payment'] : null,
            rows: $rows,
            custom: is_array($data['custom'] ?? null) ? $data['custom'] : [],
        );
    }

    /**
     * Whether Tebex has been paid for it: it stops offering a way to pay and offers a payment instead.
     */
    public function isPaid(): bool
    {
        return $this->complete || $this->paymentUrl !== null;
    }
}
