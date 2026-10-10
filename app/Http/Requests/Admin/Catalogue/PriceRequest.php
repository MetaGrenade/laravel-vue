<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PriceRequest extends FormRequest
{
    /**
     * An amount with at most two decimals, for example 25 or 25.50.
     */
    private const AMOUNT = '/^\d{1,8}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The product or variant being priced, and the price being edited if there is one.
     *
     * @return array{0: Product|ProductVariant, 1: Price|null}
     */
    private function subject(): array
    {
        $price = $this->route('price');

        if ($price instanceof Price) {
            $owner = $price->priceable;

            return [$owner instanceof Product || $owner instanceof ProductVariant ? $owner : throw new \LogicException('A price belongs to a product or variant.'), $price];
        }

        $owner = $this->route('variant') ?? $this->route('product');

        return [$owner instanceof Product || $owner instanceof ProductVariant ? $owner : throw new \LogicException('A price needs a product or variant.'), null];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:'.self::AMOUNT],
            'compare_at_amount' => ['nullable', 'regex:'.self::AMOUNT],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter a price such as 25 or 25.50.',
            'compare_at_amount.regex' => 'Enter a price such as 30 or 29.99.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['amount', 'compare_at_amount'])) {
                return;
            }

            [$owner, $price] = $this->subject();
            $currency = $price->currency ?? strtoupper((string) config('commerce.currency', 'USD'));
            $amount = Money::parse((string) $this->input('amount'), $currency);

            // A price of zero is allowed: it makes a free product (a free download), whose orders cost
            // nothing and are paid on the spot. The amount format already refuses a negative price.

            // A zero-decimal currency (yen) has no cents, so 500.50 would be charged as 501.
            if (Money::exponentFor($currency) === 0 && preg_match('/\.\d*[1-9]/', (string) $this->input('amount'))) {
                $validator->errors()->add('amount', "{$currency} has no cents; enter a whole amount.");

                return;
            }

            $compare = $this->input('compare_at_amount');

            if (filled($compare) && Money::parse((string) $compare, $currency)->minor <= $amount->minor) {
                $validator->errors()->add('compare_at_amount', 'The original price must be higher than the price, or leave it empty.');
            }

            // Checkout uses the one active price in the shop's currency; two would be ambiguous.
            if ($this->boolean('is_active', $price->is_active ?? true)) {
                $clash = Price::query()
                    ->where('priceable_type', $owner->getMorphClass())
                    ->where('priceable_id', $owner->getKey())
                    ->where('currency', $currency)
                    ->where('is_active', true)
                    ->when($price, fn ($query, Price $current) => $query->whereKeyNot($current->id))
                    ->exists();

                if ($clash) {
                    $validator->errors()->add('is_active', 'There is already an active price. Change that one, or switch it off first.');
                }
            }
        });
    }
}
