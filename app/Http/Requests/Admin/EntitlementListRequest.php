<?php

namespace App\Http\Requests\Admin;

use App\Models\Entitlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What one thing gives (a Tebex package, a subscription plan), as the whole list: whatever is not in it is taken off. A
 * limit needs an amount; a feature needs nothing, and an amount sent with one is dropped.
 */
abstract class EntitlementListRequest extends FormRequest
{
    /**
     * What to say when a limit is given with no amount.
     */
    abstract protected function missingAmount(Entitlement $entitlement): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entitlements' => ['present', 'array', 'max:50'],
            'entitlements.*.id' => ['required', 'integer', 'distinct', 'exists:entitlements,id'],
            'entitlements.*.value' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rows = (array) $this->input('entitlements', []);

            $entitlements = Entitlement::query()
                ->whereIn('id', collect($rows)->pluck('id')->filter(fn ($id) => is_numeric($id))->all())
                ->get()
                ->keyBy('id');

            foreach ($rows as $index => $row) {
                $entitlement = $entitlements->get($row['id'] ?? 0);

                if ($entitlement?->isLimit() && blank($row['value'] ?? null)) {
                    $validator->errors()->add("entitlements.{$index}.value", $this->missingAmount($entitlement));
                }
            }
        }];
    }
}
