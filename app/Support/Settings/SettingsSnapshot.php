<?php

namespace App\Support\Settings;

use App\Models\SystemSetting;

/**
 * Every system setting, read with one query the first time a request asks for any of them (docs/query-budgets.md). Before this each
 * `SystemSetting::get()` was a query of its own, and a page asks for maintenance mode, the website sections, the sign-in providers,
 * the legal pages and more, some of them several times: most of the queries a guest page ran were reading settings.
 *
 * It is bound once for each request (`scoped`), so a queue worker and Octane start every job and request from the table again, and a
 * setting changed by somebody else is seen by the next request. Within a request a change made through the model (`SystemSetting::set()`,
 * or saving or deleting a row) forgets it, and so does rolling back a transaction, so the next read is of what the table now holds.
 * A change that goes round the model (`SystemSetting::query()->update()`, `DB::table()`) cannot be noticed: call `forget()` after it.
 */
final class SettingsSnapshot
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values()[$key] ?? $default;
    }

    public function forget(): void
    {
        $this->values = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        return $this->values ??= SystemSetting::query()->get()->pluck('value', 'key')->all();
    }
}
