<?php

namespace App\Http\Requests\Admin;

use App\Models\DataExport;
use App\Rules\StorableDate;
use App\Support\Http\RequestText;
use App\Support\Localization\BrowserTime;
use Illuminate\Contracts\Validation\Validator;
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
            // The member downloads whatever this names, and PruneDataExports deletes it: so it has to be that member's own export, and
            // not somebody else's or another file on the private disk (a support attachment, a forum attachment, a digital product).
            'file_path' => ['nullable', 'string', 'max:255', 'regex:'.DataExport::pathPattern($this->memberId())],
            'failure_reason' => ['nullable', 'string', 'max:16000'],
            'completed_at' => ['nullable', 'date', new StorableDate],
        ];
    }

    public function messages(): array
    {
        return [
            'file_path.required' => 'A file path is required when marking an export as completed.',
            'file_path.regex' => "The path has to name this member's own export file, starting with ".DataExport::filePrefix($this->memberId()).'.',
        ];
    }

    /**
     * Whose export is being changed.
     */
    private function memberId(): int
    {
        $export = $this->route('export');

        return $export instanceof DataExport ? (int) $export->user_id : 0;
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
            $status = RequestText::input($this, 'status');
            $filePath = RequestText::input($this, 'file_path');

            if ($status === DataExport::STATUS_COMPLETED && $filePath === '') {
                $validator->errors()->add('file_path', 'A file path is required when marking an export as completed.');
            }

            // Of a path and a time that are themselves good: a path that was refused is not looked up on the disk, and what is not a
            // date is not read as one. Only a finished export keeps a time.
            if ($status === DataExport::STATUS_COMPLETED && ! $validator->errors()->hasAny(['file_path', 'completed_at'])) {
                $this->refuseAFinishThatCannotBe($validator, $filePath);
            }
        });
    }

    /**
     * The member's window runs from the time an export was completed, so that time cannot be one that is still to come, nor one after
     * its file was written (DataExport::latestFinish()). A few minutes past either is a fast clock, and the controller takes it back.
     */
    private function refuseAFinishThatCannotBe(Validator $validator, string $filePath): void
    {
        $typed = BrowserTime::parse(RequestText::input($this, 'completed_at'));

        if ($typed === null) {
            return;
        }

        if ($typed->greaterThan(now()->addMinutes(DataExport::CLOCK_SLACK_MINUTES))) {
            $validator->errors()->add('completed_at', 'The time cannot be in the future.');
        } elseif ($typed->greaterThan(DataExport::latestFinish($filePath)->addMinutes(DataExport::CLOCK_SLACK_MINUTES))) {
            $validator->errors()->add('completed_at', 'The time cannot be after the file was written. Leave it empty to use that time.');
        }
    }
}
