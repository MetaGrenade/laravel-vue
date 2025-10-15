<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidDateInterval;
use App\Support\SupportSlaConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportSlaRequest extends FormRequest
{
    protected const PRIORITY_ORDER = [
        'low' => 0,
        'medium' => 1,
        'high' => 2,
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('support.acp.edit');
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (SupportSlaConfiguration::PRIORITIES as $priority) {
            $after = data_get($input, "priority_escalations.$priority.after");
            if ($after !== null) {
                $after = trim((string) $after);
                data_set($input, "priority_escalations.$priority.after", $after === '' ? null : $after);
            }

            $to = data_get($input, "priority_escalations.$priority.to");
            if ($to !== null) {
                $to = trim((string) $to);
                data_set($input, "priority_escalations.$priority.to", $to === '' ? null : $to);
            }

            $threshold = data_get($input, "reassign_after.$priority");
            if ($threshold !== null) {
                $threshold = trim((string) $threshold);
                data_set($input, "reassign_after.$priority", $threshold === '' ? null : $threshold);
            }
        }

        $this->replace($input);
    }

    public function rules(): array
    {
        $rules = [
            'priority_escalations' => ['required', 'array'],
            'reassign_after' => ['required', 'array'],
        ];

        foreach (SupportSlaConfiguration::PRIORITIES as $priority) {
            $afterKey = "priority_escalations.$priority.after";
            $toKey = "priority_escalations.$priority.to";
            $reassignKey = "reassign_after.$priority";

            $rules[$afterKey] = ['nullable', 'string', 'max:255', 'required_with:'.$toKey, new ValidDateInterval()];

            $allowedTargets = array_filter(
                array_keys(self::PRIORITY_ORDER),
                fn (string $candidate) => self::PRIORITY_ORDER[$candidate] > self::PRIORITY_ORDER[$priority]
            );

            $targetRules = ['nullable'];
            if (! empty($allowedTargets)) {
                $targetRules[] = Rule::in($allowedTargets);
                $targetRules[] = 'required_with:'.$afterKey;
            } else {
                $targetRules[] = Rule::prohibitedIf(fn () => filled($this->input($afterKey)));
            }

            $rules[$toKey] = $targetRules;

            $rules[$reassignKey] = ['nullable', 'string', 'max:255', new ValidDateInterval()];
        }

        return $rules;
    }
}
