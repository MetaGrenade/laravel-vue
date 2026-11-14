<?php

namespace App\Support\Search;

use App\Support\Text\PlainText;

/**
 * The plain words stored in a search document. Markup is not kept, and the
 * text stops at the configured length so one record cannot fill the index.
 *
 * Which of the two to call depends on what the field holds, and the two are not interchangeable:
 *
 *  - `html()` for a rich-text body (a blog post, a forum post), which is stored as the sanitiser makes it: `Don't` is `Don&#039;t`, and
 *    the paragraphs have no space between them. It gives the words the member wrote ({@see PlainText}).
 *  - `plain()` for a field that holds text as it was typed (an FAQ answer, a product description, a blog excerpt, a thread's excerpt,
 *    which is made as text when the thread is). An `&amp;` in it is the five characters the author typed and the page shows, so it is
 *    kept; decoding it would index `&`, and a search for *amp* would miss an answer that is on the page.
 */
final class SearchText
{
    public static function plain(mixed $value, ?int $limit = null): string
    {
        return self::cut(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value))), $limit);
    }

    public static function html(mixed $value, ?int $limit = null): string
    {
        return self::cut(PlainText::of($value), $limit);
    }

    private static function cut(string $text, ?int $limit): string
    {
        $limit ??= max(1, (int) config('search.documents.max_characters', 8000));

        if ($limit < 1) {
            return '';
        }

        return mb_substr($text, 0, $limit);
    }
}
