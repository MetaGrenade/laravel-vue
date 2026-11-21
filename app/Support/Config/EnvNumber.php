<?php

namespace App\Support\Config;

use Illuminate\Support\Env;

/**
 * A number a config file reads from the environment.
 *
 * `(int) env('SEARCH_TIMEOUT', 5)` is 5 only while the line is absent. `SEARCH_TIMEOUT=` (a line copied from a template and not filled
 * in, or cleared to go back to the default) is an empty text, which `env()` hands over as it is and `(int)` makes 0. None of these
 * settings means "not set" by 0: a timeout of 0 is no limit at all, a queue that gives a job back after 0 seconds runs it twice at
 * once, a session that lasts 0 minutes never signs anybody in, HSTS with a max-age of 0 is HSTS switched off, and a webhook tolerance
 * of 0 is no tolerance check. The settings that were not cast (`env('FORUM_INDEX_CACHE_TTL', 300)`) stayed an empty text, which the code
 * that read them took for 0 or for no value, depending on the setting.
 *
 * Here nothing, an empty value and anything that is not a number are the default: what the line says when it is not there. Whoever
 * means 0 writes `0`. A number is read as it always was (`015` is 15, `2.5` is 2 as a whole number), and a negative one stays negative.
 *
 * The one exception is a setting whose documentation says what an empty line means (`API_SIGN_IN_EXPIRY_DAYS`: "empty or 0 is no end").
 * It says so where it is read, with `$whenBlank`, so that the exception is written next to the setting and a test can list it. Words
 * and other things that are not numbers are still the default.
 */
final class EnvNumber
{
    /**
     * A whole number, or `$default` when the setting is not there, or is not a number.
     *
     * @param  int|null  $whenBlank  what an empty line (or one of only spaces) is, for a setting that documents a meaning for it; null: the default
     */
    public static function integer(string $key, int $default, ?int $whenBlank = null): int
    {
        $value = Env::get($key);

        if ($whenBlank !== null && is_string($value) && trim($value) === '') {
            return $whenBlank;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    /** A number that may have a fraction (seconds, say), or `$default` when the setting is not there, is empty, or is not a number. */
    public static function float(string $key, float $default): float
    {
        $value = Env::get($key);

        return is_string($value) && is_numeric($value) ? (float) $value : $default;
    }
}
