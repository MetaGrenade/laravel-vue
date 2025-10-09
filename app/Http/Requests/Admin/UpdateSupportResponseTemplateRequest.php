<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupportResponseTemplateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [
            'support_ticket_category_id' => $this->normalizeNullableInt('support_ticket_category_id'),
        ];

        if ($this->has('support_team_ids')) {
            $payload['support_team_ids'] = $this->normalizeIdsArray($this->input('support_team_ids'));
        }

        if ($this->has('is_active')) {
            $value = $this->input('is_active');
            $payload['is_active'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($payload['is_active'] === null) {
                $payload['is_active'] = (bool) $value;
            }
        }

        $this->merge($payload);
    }

    public function authorize(): bool
    {
        return $this->user()->can('support_templates.acp.edit');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'support_ticket_category_id' => ['nullable', 'integer', 'exists:support_ticket_categories,id'],
            'support_team_ids' => ['array'],
            'support_team_ids.*' => ['integer', 'exists:support_teams,id'],
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

    /**
     * @param  mixed  $value
     * @return array<int, int>
     */
    private function normalizeIdsArray(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'null') {
            return [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->filter(fn ($id) => $id !== null && $id !== '' && $id !== 'null')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
