<?php

namespace App\Http\Requests\Admin;

use App\Models\DataExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('trust_safety.acp.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DataExport::STATUSES)],
            'file_path' => ['nullable', 'string', 'max:255'],
            'failure_reason' => ['nullable', 'string'],
            'completed_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'file_path.required' => 'A file path is required when marking an export as completed.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $filePath = $this->input('file_path');
        $failureReason = $this->input('failure_reason');

        $this->merge([
            'file_path' => is_string($filePath) && trim($filePath) === '' ? null : $filePath,
            'failure_reason' => is_string($failureReason) && trim($failureReason) === '' ? null : $failureReason,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $status = $this->string('status')->toString();
            $filePath = $this->string('file_path')->toString();

            if ($status === DataExport::STATUS_COMPLETED && $filePath === '') {
                $validator->errors()->add('file_path', 'A file path is required when marking an export as completed.');
            }
        });
    }
}
