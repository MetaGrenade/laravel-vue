<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels' => ['required', 'array'],
            'channels.mail' => ['required', 'boolean'],
            'channels.push' => ['required', 'boolean'],
            'channels.database' => ['required', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
