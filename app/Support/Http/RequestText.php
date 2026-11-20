<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Text read from a request, for the places that need a string.
 *
 * A query string can say `?search[]=a` or `?search[a]=b`, and PHP then hands back a list where the code asked for text. `(string)` on a
 * list is a warning ("Array to string conversion"), which Laravel raises as an exception, and `$request->string()` casts the same way:
 * the page answered 500 for a request nobody meant. Scanners send `[]` on every name they find, so these were in the logs.
 *
 * Here a string comes back as it was sent, a number as its text, and anything else (a list, nothing at all) as `$default`, which is the
 * same as the parameter not having been sent. Reads that really want a list (`types[]` of the search) do not use this.
 */
final class RequestText
{
    /** A value of the query string only (what `$request->query($key)` gives), as text. */
    public static function query(Request $request, string $key, string $default = ''): string
    {
        return self::text($request->query($key), $default);
    }

    /** A value of the query string or of the body (what `$request->input($key)` gives), as text. */
    public static function input(Request $request, string $key, string $default = ''): string
    {
        return self::text($request->input($key), $default);
    }

    private static function text(mixed $value, string $default): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '',
            default => $default,
        };
    }
}
