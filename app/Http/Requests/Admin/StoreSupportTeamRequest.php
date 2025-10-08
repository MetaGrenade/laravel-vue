<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTeamRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }

        if ($this->has('member_ids')) {
            $this->merge([
                'member_ids' => $this->normalizeIdsArray($this->input('member_ids')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('support_teams.acp.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:support_teams,name'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
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
