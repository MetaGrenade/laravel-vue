<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['slug', 'starts_at', 'ends_at'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        $poll = $this->route('poll');

        $pollId = $poll instanceof Model
            ? $poll->getKey()
            : (is_numeric($poll) ? (int) $poll : null);

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('polls', 'slug')->ignore($pollId),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(['draft', 'published', 'closed'])],
            'allow_multiple' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer', 'exists:poll_options,id'],
            'options.*.label' => ['required', 'string', 'max:255'],
        ];
    }
}
