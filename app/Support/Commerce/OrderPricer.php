<?php

namespace App\Support\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\TaxRate;
use App\Support\Commerce\Discounts\CouponRejected;
use App\Support\Commerce\Discounts\Discount;
use App\Support\Commerce\Discounts\DiscountCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Prices a cart from the catalogue, then adds shipping and tax for where it is
 * going.
 *
 * Nothing stored in the cart is trusted. Every line is looked up and priced
 * again, so a stale or tampered cart cannot buy something at the wrong price or
 * that has been withdrawn. The same code produces the live quote on the checkout
 * page (lenient: it reports what is missing) and the order that is placed
 * (strict: it refuses anything incomplete or no longer offered).
 *
 * A discount code on the cart is checked again each time. Shipping is chosen from the cart before
 * any discount (a code never changes which rates are offered), the discount comes off the items or
 * the shipping charge, and tax is charged on what is left.
 */
class OrderPricer
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly ShippingCalculator $shipping,
        private readonly TaxCalculator $tax,
        private readonly DiscountCalculator $discounts,
    ) {}

    /**
     * Whether any item in the cart has to be shipped.
     */
    public function cartNeedsShipping(Cart $cart): bool
    {
        $cart->loadMissing('items.product');

        return $cart->items->contains(fn (CartItem $item) => $item->product === null || $item->product->requires_shipping);
    }

    /**
     * @param  CustomerDetails|null  $customer  Who is buying, when known (it decides a code's per-customer limit).
     *
     * @throws CheckoutException When something in the cart cannot be bought, or (strict) the order is incomplete
     *                           or the discount code on the cart can no longer be used.
     * @throws InvalidCheckoutInput When (strict) an address or shipping method cannot be accepted.
     */
    public function price(
        Cart $cart,
        ?Destination $shipTo = null,
        ?Destination $billTo = null,
        ?int $shippingRateId = null,
        bool $strict = false,
        ?CustomerDetails $customer = null,
    ): Pricing {
        $cart->loadMissing(['items.product', 'items.variant', 'coupon']);

        if ($cart->items->isEmpty()) {
            throw new CheckoutException('Your cart is empty.');
        }

        $currency = $this->prices->currency();
        $lines = $cart->items->map(fn (CartItem $item) => $this->line($item))->values()->all();

        $subtotal = Money::zero($currency);
        $shippable = Money::zero($currency);

        foreach ($lines as $line) {
            $subtotal = $subtotal->add($line->subtotal);

            if ($line->requiresShipping) {
                $shippable = $shippable->add($line->subtotal);
            }
        }

        $needsShipping = collect($lines)->contains(fn (PricedLine $line) => $line->requiresShipping);

        [$options, $selected, $canShip, $message] = $this->resolveShipping($needsShipping, $shippable, $shipTo, $shippingRateId, $strict);

        // Tax follows where the goods go; for digital-only orders, the billing address.
        $taxDestination = $needsShipping ? ($shipTo ?? $billTo) : ($billTo ?? $shipTo);

        $regionRequired = $taxDestination !== null && $this->tax->needsRegion($taxDestination->country);

        if ($strict && $regionRequired && blank($taxDestination->region)) {
            $field = ($needsShipping && $shipTo !== null) ? 'shipping_address.region' : 'billing_address.region';

            throw new InvalidCheckoutInput('Please enter your state or region so tax can be calculated.', $field);
        }

        $rates = $this->tax->ratesFor($taxDestination?->country, $taxDestination?->region);
        $shippingAmount = $selected->amount ?? Money::zero($currency);

        $coupon = $cart->coupon;
        $couponCode = $coupon !== null ? $coupon->code : null;
        $discount = null;
        $problem = null;

        // The shipping charge is real once a rate is chosen, or an address is given and the shop has no
        // rates to charge. Until then (no address yet) the zero above is only a placeholder.
        $shippingKnown = $needsShipping && $canShip && $message === null && ($selected !== null || $shipTo !== null);

        if ($coupon !== null) {
            try {
                $discount = $this->discounts->calculate($coupon, $lines, $needsShipping, $shippingAmount, $customer, $cart, $shippingKnown);
            } catch (CouponRejected $rejected) {
                $problem = $rejected->getMessage();
            }
        }

        $pricing = $this->assemble($currency, $lines, $subtotal, $needsShipping, $options, $selected, $canShip, $message, $rates, $shippingAmount, $regionRequired, $discount, $couponCode, $problem);

        // A code is not allowed to make the whole order free: payment providers cannot take a payment
        // of nothing, so the order would have no way to be paid.
        if ($discount !== null && $pricing->grandTotal->minor <= 0) {
            $problem = "That code can't be used on this order because it would make it free.";
            $pricing = $this->assemble($currency, $lines, $subtotal, $needsShipping, $options, $selected, $canShip, $message, $rates, $shippingAmount, $regionRequired, null, $couponCode, $problem);
        }

        if ($strict && $problem !== null) {
            throw new CheckoutException("Your discount code {$couponCode} can't be used. {$problem}");
        }

        return $pricing;
    }

    /**
     * Put the quote together with or without a discount: tax is worked out on what the customer
     * pays for the items and the shipping after it.
     *
     * @param  list<PricedLine>  $lines
     * @param  list<ShippingOption>  $options
     * @param  Collection<int, TaxRate>  $rates
     */
    private function assemble(
        string $currency,
        array $lines,
        Money $subtotal,
        bool $needsShipping,
        array $options,
        ?ShippingOption $selected,
        bool $canShip,
        ?string $message,
        Collection $rates,
        Money $shippingAmount,
        bool $regionRequired,
        ?Discount $discount,
        ?string $couponCode,
        ?string $couponProblem,
    ): Pricing {
        if ($discount !== null) {
            foreach ($lines as $index => $line) {
                $lines[$index] = $line->withDiscount($discount->forLine($index));
            }
        }

        $taxableItems = Money::zero($currency);

        foreach ($lines as $line) {
            if ($line->taxable) {
                $taxableItems = $taxableItems->add($line->net());
            }
        }

        $shippingToTax = $shippingAmount->subtract($discount->onShipping ?? Money::zero($currency));
        $taxResult = $this->tax->calculate($rates, $taxableItems, $shippingToTax);

        $lines = $this->spreadItemTax($lines, $taxResult->onItems);
        $discounted = $discount?->total() ?? Money::zero($currency);
        $grandTotal = $subtotal->add($shippingAmount)->add($taxResult->total())->subtract($discounted);

        return new Pricing(
            currency: $currency,
            lines: $lines,
            subtotal: $subtotal,
            needsShipping: $needsShipping,
            shippingOptions: $options,
            shipping: $selected,
            canShip: $canShip,
            shippingMessage: $message,
            taxLines: $taxResult->lines,
            itemTax: $taxResult->onItems,
            shippingTax: $taxResult->onShipping,
            discount: $discounted,
            grandTotal: $grandTotal,
            regionRequired: $regionRequired,
            applied: $discount,
            couponCode: $couponCode,
            couponProblem: $couponProblem,
        );
    }

    /**
     * @return array{0: list<ShippingOption>, 1: ShippingOption|null, 2: bool, 3: string|null}
     */
    private function resolveShipping(bool $needsShipping, Money $shippable, ?Destination $shipTo, ?int $rateId, bool $strict): array
    {
        if (! $needsShipping) {
            return [[], null, true, null];
        }

        if ($shipTo === null) {
            if ($strict) {
                throw new InvalidCheckoutInput('Please enter a shipping address.', 'shipping_address');
            }

            return [[], null, true, 'Enter your shipping address to see shipping options.'];
        }

        // Shipping not set up yet: accept any country and charge nothing for it.
        if (! $this->shipping->isConfigured()) {
            return [[], null, true, null];
        }

        $options = $this->shipping->optionsFor($shipTo->country, $shippable)->all();

        if ($options === []) {
            $message = "Sorry, we can't ship this order to ".Countries::name($shipTo->country).'.';

            if ($strict) {
                throw new InvalidCheckoutInput($message, 'shipping_address.country');
            }

            return [[], null, false, $message];
        }

        $selected = null;

        if ($rateId !== null) {
            $selected = collect($options)->first(fn (ShippingOption $option) => $option->id === $rateId);

            if ($selected === null && $strict) {
                throw new InvalidCheckoutInput('That shipping method is no longer available. Please choose another.', 'shipping_rate_id');
            }
        }

        return [$options, $selected ?? $options[0], true, null];
    }

    /**
     * Share the tax charged on items between the taxable lines in proportion to
     * what the customer pays for each (after any discount), so the line amounts
     * add up to the total exactly.
     *
     * @param  list<PricedLine>  $lines
     * @return list<PricedLine>
     */
    private function spreadItemTax(array $lines, Money $itemTax): array
    {
        $taxable = array_keys(array_filter($lines, fn (PricedLine $line) => $line->taxable));

        if ($taxable === [] || $itemTax->isZero()) {
            return $lines;
        }

        $shares = $itemTax->allocate(array_map(fn (int $index) => $lines[$index]->net()->minor, $taxable));

        foreach ($taxable as $position => $index) {
            $lines[$index] = $lines[$index]->withTax($shares[$position]);
        }

        return $lines;
    }

    private function line(CartItem $item): PricedLine
    {
        $product = $item->product;
        $variant = $item->variant;
        $name = $product !== null ? $product->name : ($item->snapshot['product']['name'] ?? 'This item');

        if ($product === null || ! $product->is_active) {
            throw new CheckoutException("{$name} is no longer available.");
        }

        if ($item->product_variant_id !== null && ($variant === null || $variant->product_id !== $product->id || ! $variant->is_active)) {
            throw new CheckoutException("{$name} is no longer available in the option you chose.");
        }

        // A cart from before the product gained variants holds a line for the base product. It is
        // not sold as that any more.
        if ($item->product_variant_id === null && $product->variants()->exists()) {
            throw new CheckoutException("{$name} is no longer available in the option you chose.");
        }

        $max = (int) config('commerce.checkout.max_quantity', 20);

        if ($item->quantity < 1 || $item->quantity > $max) {
            throw new CheckoutException("You can buy between 1 and {$max} of {$name} at a time.");
        }

        $price = $this->prices->resolve($product, $variant);

        if ($price === null) {
            throw new CheckoutException("{$name} can't be purchased right now.");
        }

        $unit = Money::parse($price->amount, $price->currency);
        $description = $variant ? "{$product->name} — {$variant->name}" : $product->name;

        return new PricedLine(
            item: $item,
            description: Str::limit($description, 250, ''),
            unit: $unit,
            subtotal: $unit->multiply($item->quantity),
            requiresShipping: (bool) $product->requires_shipping,
            taxable: (bool) $product->is_taxable,
            metadata: array_filter([
                'product' => $product->name,
                'variant' => $variant?->name,
                'sku' => $variant?->sku,
                'price_id' => $price->id,
            ], fn ($value) => $value !== null),
            tax: Money::zero($unit->currency),
        );
    }
}
