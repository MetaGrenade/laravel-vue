<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportResponseTemplateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [
            'support_ticket_category_id' => $this->normalizeNullableInt('support_ticket_category_id'),
            'support_team_id' => $this->normalizeNullableInt('support_team_id'),
        ];

        $value = $this->input('is_active', true);
        $payload['is_active'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($payload['is_active'] === null) {
            $payload['is_active'] = (bool) $value;
        }

        $this->merge($payload);
    }

    public function authorize(): bool
    {
        return $this->user()->can('support_templates.acp.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'support_ticket_category_id' => ['nullable', 'integer', 'exists:support_ticket_categories,id'],
            'support_team_id' => ['nullable', 'integer', 'exists:support_teams,id'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function normalizeNullableInt(string $key): ?int
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = $this->input($key);

        if ($value === '' || $value === 'null') {
            return null;
        }

        return $value !== null ? (int) $value : null;
    }
}
