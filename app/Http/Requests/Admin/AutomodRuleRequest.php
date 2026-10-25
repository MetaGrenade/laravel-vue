<?php

namespace App\Http\Requests\Admin;

use App\Models\AutomodRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Making or changing an automatic moderation rule (docs/automod.md). What a rule needs depends on its type, so what is asked for does
 * too, and only what the type uses is kept.
 */
class AutomodRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.automod') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A rule keeps its type once it is made: what it looks for is what it is.
        $type = $this->route('rule') instanceof AutomodRule ? $this->route('rule')->type : $this->input('type');

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => [$this->route('rule') instanceof AutomodRule ? 'sometimes' : 'required', Rule::in(AutomodRule::TYPES)],
            'action' => ['required', Rule::in(AutomodRule::ACTIONS)],
            'only_new_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_enabled' => ['sometimes', 'boolean'],
            ...match ($type) {
                AutomodRule::WORDS => [
                    'settings.phrases' => ['required', 'array', 'min:1', 'max:500'],
                    // A blank line is left out, not an error; that there is a phrase at all is checked in after().
                    'settings.phrases.*' => ['nullable', 'string', 'max:120'],
                    'settings.whole_words' => ['sometimes', 'boolean'],
                ],
                AutomodRule::LINKS => [
                    'settings.max_links' => ['nullable', 'integer', 'min:0', 'max:100', 'required_without:settings.blocked_domains'],
                    'settings.blocked_domains' => ['nullable', 'array', 'max:500', 'required_without:settings.max_links'],
                    'settings.blocked_domains.*' => ['nullable', 'string', 'max:190'],
                ],
                AutomodRule::DUPLICATE => [
                    'settings.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                    'settings.copies' => ['required', 'integer', 'min:1', 'max:20'],
                ],
                AutomodRule::RATE => [
                    'settings.posts' => ['required', 'integer', 'min:1', 'max:100'],
                    'settings.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                ],
                default => [],
            },
        ];
    }

    /**
     * A list of nothing but blank lines is no list.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->route('rule') instanceof AutomodRule ? $this->route('rule')->type : $this->input('type');
            $settings = (array) $this->input('settings', []);

            if ($type === AutomodRule::WORDS && ! $validator->errors()->has('settings.phrases') && $this->lines((array) ($settings['phrases'] ?? [])) === []) {
                $validator->errors()->add('settings.phrases', 'Give at least one phrase.');
            }

            if ($type === AutomodRule::LINKS && ! $validator->errors()->hasAny(['settings.max_links', 'settings.blocked_domains'])
                && ! is_numeric($settings['max_links'] ?? null) && $this->lines((array) ($settings['blocked_domains'] ?? [])) === []) {
                $validator->errors()->add('settings.max_links', 'Give a number of links, or at least one domain.');
            }
        }];
    }

    /**
     * What to store: the rule's own columns, and of the settings only what its type uses (and as the type wants them).
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $validated = $this->validated();
        $type = $this->route('rule') instanceof AutomodRule ? $this->route('rule')->type : $validated['type'];
        $given = (array) ($validated['settings'] ?? []);

        $settings = match ($type) {
            AutomodRule::WORDS => [
                'phrases' => $this->lines($given['phrases'] ?? []),
                'whole_words' => (bool) ($given['whole_words'] ?? false),
            ],
            AutomodRule::LINKS => [
                'max_links' => isset($given['max_links']) ? (int) $given['max_links'] : null,
                'blocked_domains' => $this->lines($given['blocked_domains'] ?? []),
            ],
            AutomodRule::DUPLICATE => [
                'minutes' => (int) $given['minutes'],
                'copies' => (int) $given['copies'],
            ],
            default => [
                'posts' => (int) $given['posts'],
                'minutes' => (int) $given['minutes'],
            ],
        };

        return [
            'name' => trim($validated['name']),
            'type' => $type,
            'settings' => $settings,
            'action' => $validated['action'],
            'only_new_days' => $validated['only_new_days'] ?? null,
            'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function lines(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn (string $value) => $value !== '')));
    }
}
