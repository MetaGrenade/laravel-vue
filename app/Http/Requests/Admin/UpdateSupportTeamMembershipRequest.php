<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupportTeamMembershipRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('team_ids')) {
            $this->merge([
                'team_ids' => $this->normalizeIdsArray($this->input('team_ids')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('support_teams.acp.edit');
    }

    public function rules(): array
    {
        return [
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', 'exists:support_teams,id'],
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
