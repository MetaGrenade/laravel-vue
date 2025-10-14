<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportAssignmentRuleRequest extends FormRequest
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

        if ($this->has('assigned_to')) {
            $this->merge([
                'assigned_to' => $this->normalizeNullableId($this->input('assigned_to')),
            ]);
        }

        if ($this->has('active')) {
            $this->merge([
                'active' => filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('support_assignment_rules.acp.edit');
    }

    public function rules(): array
    {
        return [
            'support_ticket_category_id' => ['nullable', 'integer', 'exists:support_ticket_categories,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
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
