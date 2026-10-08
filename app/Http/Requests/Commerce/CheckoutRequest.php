<?php

namespace App\Http\Requests\Commerce;

use App\Models\Address;
use App\Models\Cart;
use App\Models\TaxRate;
use App\Support\Commerce\AddressData;
use App\Support\Commerce\AddressRules;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutInput;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\ShippingCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The checkout form. Which addresses are needed depends on the cart:
 *
 * - Anything that has to be shipped needs a shipping address. A billing address
 *   is only asked for when the customer says it differs.
 * - A cart of digital goods ships nowhere, so it needs a billing address only
 *   when the shop charges tax by location.
 *
 * An address can be typed in or picked from the signed-in customer's address book.
 */
class CheckoutRequest extends FormRequest
{
    private ?Cart $cart = null;

    private bool $cartLoaded = false;

    public function authorize(): bool
    {
        return true;
    }

    public function cart(): ?Cart
    {
        if (! $this->cartLoaded) {
            $this->cart = CartManager::forRequest($this);
            $this->cartLoaded = true;
        }

        return $this->cart;
    }

    protected function prepareForValidation(): void
    {
        foreach (['shipping_address', 'billing_address'] as $key) {
            if (is_array($this->input($key))) {
                $this->merge([$key => AddressRules::normalise($this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => [$this->user() ? 'nullable' : 'required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'token' => ['required', 'uuid'],
            'shipping_rate_id' => ['nullable', 'integer'],
            'shipping_address_id' => ['nullable', 'integer'],
            'billing_address_id' => ['nullable', 'integer'],
            'billing_same_as_shipping' => ['sometimes', 'boolean'],
            'save_addresses' => ['sometimes', 'boolean'],
        ];

        if ($this->mustTypeShippingAddress()) {
            $rules += AddressRules::for('shipping_address', $this->input('shipping_address.country'));
        }

        if ($this->mustTypeBillingAddress()) {
            $rules += AddressRules::for('billing_address', $this->input('billing_address.country'));
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...AddressRules::attributes('shipping_address'),
            ...AddressRules::attributes('billing_address'),
            'shipping_address' => 'shipping address',
            'billing_address' => 'billing address',
            'shipping_address_id' => 'saved address',
            'billing_address_id' => 'saved address',
            'shipping_rate_id' => 'shipping method',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['shipping_address_id', 'billing_address_id'] as $key) {
                if ($this->filled($key) && $this->savedAddress((int) $this->input($key)) === null) {
                    $validator->errors()->add($key, 'That saved address could not be found.');
                }
            }

            if ($this->mustTypeShippingAddress()) {
                $this->checkWeShipTo($validator);
            }
        });
    }

    public function checkoutInput(): CheckoutInput
    {
        return new CheckoutInput(
            shippingAddress: $this->cartNeedsShipping() ? $this->resolveAddress('shipping') : null,
            billingAddress: $this->needsBillingAddress() ? $this->resolveAddress('billing') : null,
            shippingRateId: $this->filled('shipping_rate_id') ? $this->integer('shipping_rate_id') : null,
        );
    }

    /**
     * The address the customer typed in (rather than picked from their saved
     * ones), which they may ask to have saved to their address book. Only an
     * address that is actually part of this order counts: a billing address that
     * was posted but is not used (it is the same as shipping, or no tax needs it)
     * is ignored.
     */
    public function typedAddress(string $kind): ?AddressData
    {
        $partOfOrder = $kind === 'shipping' ? $this->cartNeedsShipping() : $this->needsBillingAddress();

        if (! $partOfOrder || $this->filled("{$kind}_address_id") || ! is_array($this->input("{$kind}_address"))) {
            return null;
        }

        return AddressData::fromArray($this->input("{$kind}_address"));
    }

    private function cartNeedsShipping(): bool
    {
        $cart = $this->cart();

        return $cart !== null && app(OrderPricer::class)->cartNeedsShipping($cart);
    }

    /**
     * A shipping address is needed and has to be typed in (no saved one was picked).
     */
    private function mustTypeShippingAddress(): bool
    {
        return $this->cartNeedsShipping() && ! $this->filled('shipping_address_id');
    }

    /**
     * Whether a separate billing address is part of this order at all.
     */
    private function needsBillingAddress(): bool
    {
        if ($this->cartNeedsShipping()) {
            return $this->has('billing_same_as_shipping') && ! $this->boolean('billing_same_as_shipping');
        }

        return TaxRate::query()->active()->exists();
    }

    private function mustTypeBillingAddress(): bool
    {
        return $this->needsBillingAddress() && ! $this->filled('billing_address_id');
    }

    private function resolveAddress(string $kind): ?AddressData
    {
        if ($this->filled("{$kind}_address_id")) {
            $saved = $this->savedAddress((int) $this->input("{$kind}_address_id"));

            return $saved ? AddressData::fromModel($saved) : null;
        }

        return is_array($this->input("{$kind}_address")) ? AddressData::fromArray($this->input("{$kind}_address")) : null;
    }

    private function savedAddress(int $id): ?Address
    {
        $user = $this->user();

        return $user === null ? null : Address::query()->visibleTo($user)->find($id);
    }

    private function checkWeShipTo(Validator $validator): void
    {
        $served = app(ShippingCalculator::class)->servedCountries();
        $country = $this->input('shipping_address.country');

        if ($served !== null && is_string($country) && $country !== '' && ! in_array($country, $served, true)) {
            $validator->errors()->add('shipping_address.country', "Sorry, we can't ship to that country.");
        }
    }
}
