<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\DB;

/**
 * What a person types into a search box is letters, not a pattern.
 *
 * In a `LIKE`, `%` is "anything" and `_` is "any one character", so a search for `100%` found everything that has `100` in it, a search for
 * `_` found every row there is, and `a_b` found `axb`. Escaping them needs a character that the database treats as the escape character, and
 * the three databases do not agree on one: MySQL and PostgreSQL take a backslash without being told, SQLite takes none unless the statement
 * names one (so `\_` on SQLite is a backslash and then any character, and a search for `a_b` found nothing). `!` is named in the statement
 * (`escape '!'`), which every one of them accepts the same way, so the search finds the same rows on all three.
 *
 * Use the two together: the pattern is what is bound, the clause is what it is bound to.
 *
 *     $like = LikePattern::contains($term);
 *     $query->whereRaw(LikePattern::clause('subject'), [$like])->orWhereRaw(LikePattern::clause('body'), [$like]);
 *
 * Case does not matter: the column and the pattern are both lower-cased by the database, which is what `whereLike` did on each (`like` on
 * MySQL and SQLite, `ilike` on PostgreSQL).
 */
final class LikePattern
{
    /** The character that makes the next one a letter. A `!` that is typed is escaped with it as well. */
    public const ESCAPE = '!';

    /** The text, made plain, with `%` either side: it matches wherever the text is. */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /** The text, made plain, with `%` after it: it matches what begins with the text. */
    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }

    /** The text with the three characters that mean something to `LIKE` made into letters. */
    public static function escape(string $term): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        );
    }

    /**
     * The condition for one column, with one `?` for a pattern made by this class. The column is named as the query names it
     * (`title`, `blogs.title`) and is quoted the way the database wants.
     */
    public static function clause(string $column): string
    {
        return 'lower('.DB::connection()->getQueryGrammar()->wrap($column).') like lower(?) escape \''.self::ESCAPE.'\'';
    }
}
