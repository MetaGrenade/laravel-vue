<?php

namespace App\Http\Requests\Settings;

use App\Support\NotificationChannelPreferences;
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
            'channels.support_ticket' => ['required', 'array'],
            'channels.support_ticket.mail' => ['required', 'boolean'],
            'channels.support_ticket.push' => ['required', 'boolean'],
            'channels.support_ticket.database' => ['required', 'boolean'],
            'channels.forum_subscription' => ['required', 'array'],
            'channels.forum_subscription.mail' => ['required', 'boolean'],
            'channels.forum_subscription.push' => ['required', 'boolean'],
            'channels.forum_subscription.database' => ['required', 'boolean'],
            'channels.blog_subscription' => ['required', 'array'],
            'channels.blog_subscription.mail' => ['required', 'boolean'],
            'channels.blog_subscription.push' => ['required', 'boolean'],
            'channels.blog_subscription.database' => ['required', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $channels = $this->input('channels', []);

        if (!is_array($channels)) {
            $channels = [];
        }

        $stored = optional($this->user())->notification_preferences;

        if (!is_array($stored)) {
            $stored = [];
        }

        $normalized = [];

        foreach (NotificationChannelPreferences::keys() as $key) {
            $incoming = $channels[$key] ?? [];

            if (!is_array($incoming)) {
                $incoming = [];
            }

            $existing = $stored[$key] ?? [];

            if (!is_array($existing)) {
                $existing = [];
            }

            $normalized[$key] = NotificationChannelPreferences::normalize(
                $key,
                array_merge($existing, $incoming),
            );
        }

        $this->merge([
            'channels' => array_merge($channels, $normalized),
        ]);
    }
}
