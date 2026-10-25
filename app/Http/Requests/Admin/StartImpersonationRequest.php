<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StartImpersonationRequest extends FormRequest
{
    /**
     * The route's own `can:users.acp.impersonate` decides who may; this only says what has to be sent.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Why, in the person's words (a ticket number will do): kept in the audit log with everything that is looked at.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you are viewing as this person (a ticket number will do). It is kept in the audit log.',
            'reason.min' => 'Say a little more about why: at least :min characters.',
        ];
    }
}
