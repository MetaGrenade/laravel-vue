<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Reads the validation rules written in the source of `app/Http`, for the tests that guard how they are written
 * (`StringRulesHaveALengthTest`, `NumberRulesHaveABoundTest`).
 */
final class RuleScanner
{
    /**
     * Every `'field' => [rules]` or `'field' => 'rules|with|pipes'` in the directory (`app/Http` unless one is given), as `path::field` =>
     * the text of each rule written for it. A field that is written more than once in a file (the same rule in two actions) keeps every
     * one of its rules, so the second cannot hide behind the first.
     *
     * @return array<string, list<string>>
     */
    public static function entries(?string $base = null): array
    {
        $base ??= app_path('Http');
        $entries = [];

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (preg_match_all('/\'([A-Za-z0-9_.*-]+)\'\s*=>\s*(?=\[|\'[a-z])/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $start = $match[0][1] + strlen($match[0][0]);
                $entries["{$path}::{$match[1][0]}"][] = self::valueAt($source, $start);
            }
        }

        return $entries;
    }

    /**
     * The text of one value: up to the comma, or the closing bracket, that ends it (brackets and quotes inside it are skipped over).
     */
    private static function valueAt(string $source, int $start): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                break;
            }
        }

        return substr($source, $start, $i - $start);
    }
}
