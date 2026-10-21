<?php

namespace App\Http\Requests\Admin;

use App\Enums\EntitlementType;
use App\Models\Entitlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An entitlement. Making one names it for good: code asks for it by its key and a grant's value means what its type
 * says, so only the label and the description can be changed afterwards (anything else sent is ignored).
 */
class EntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->input('key');

        if (is_string($key)) {
            $this->merge(['key' => strtolower(trim($key))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->route('entitlement') !== null) {
            return $rules;
        }

        return [
            'key' => ['required', 'string', 'max:64', 'regex:'.Entitlement::KEY_PATTERN, Rule::unique('entitlements', 'key')],
            'type' => ['required', Rule::enum(EntitlementType::class)],
            ...$rules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use lower case letters and numbers, with dots, dashes or underscores between words, starting with a letter (for example "team.seats").',
            'key.unique' => 'There is already an entitlement with that key.',
        ];
    }
}
