<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportAssignmentRuleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('support_ticket_category_id')) {
            $this->merge([
                'support_ticket_category_id' => $this->normalizeNullableId($this->input('support_ticket_category_id')),
            ]);
        }

        if ($this->has('priority')) {
            $priority = $this->input('priority');
            $this->merge([
                'priority' => $priority === '' || $priority === 'null' ? null : $priority,
            ]);
        }

        if ($this->has('assignee_type')) {
            $assigneeType = strtolower((string) $this->input('assignee_type'));

            $this->merge([
                'assignee_type' => $assigneeType,
            ]);
        }

        if ($this->has('assigned_to')) {
            $this->merge([
                'assigned_to' => $this->normalizeNullableId($this->input('assigned_to')),
            ]);
        }

        if ($this->has('support_team_id')) {
            $this->merge([
                'support_team_id' => $this->normalizeNullableId($this->input('support_team_id')),
            ]);
        }

        if ($this->has('active')) {
            $this->merge([
                'active' => filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }

        if ($this->input('assignee_type') === 'team') {
            $this->merge([
                'assigned_to' => null,
            ]);
        }

        if ($this->input('assignee_type') === 'user') {
            $this->merge([
                'support_team_id' => null,
            ]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('support_assignment_rules.acp.create');
    }

    public function rules(): array
    {
        return [
            'support_ticket_category_id' => ['nullable', 'integer', 'exists:support_ticket_categories,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'assignee_type' => ['required', Rule::in(['user', 'team'])],
            'assigned_to' => ['required_if:assignee_type,user', 'nullable', 'integer', 'exists:users,id'],
            'support_team_id' => ['required_if:assignee_type,team', 'nullable', 'integer', 'exists:support_teams,id'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (int) $value;
    }
}
