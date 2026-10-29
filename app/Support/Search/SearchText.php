<?php

namespace App\Support\Search;

/**
 * The plain words stored in a search document. Markup is not kept, and the
 * text stops at the configured length so one record cannot fill the index.
 */
final class SearchText
{
    public static function plain(mixed $value, ?int $limit = null): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $limit ??= max(1, (int) config('search.documents.max_characters', 8000));

        if ($limit < 1) {
            return '';
        }

        return mb_substr($text, 0, $limit);
    }
}
