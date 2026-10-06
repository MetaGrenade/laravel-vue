<?php

namespace App\Casts;

use App\Support\Security\HtmlSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Sanitises rich-text HTML whenever the attribute is written.
 *
 * Usage: `'body' => SanitizedHtml::class.':forum'` (or `:article`).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class SanitizedHtml implements CastsAttributes
{
    public function __construct(private readonly string $policy = 'forum')
    {
        if (! in_array($policy, ['forum', 'article'], true)) {
            throw new InvalidArgumentException("Unknown HTML sanitisation policy [{$policy}].");
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(HtmlSanitizer::class)->{$this->policy}((string) $value);
    }
}
