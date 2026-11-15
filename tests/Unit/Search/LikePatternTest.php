<?php

namespace Tests\Unit\Search;

use App\Support\Search\LikePattern;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LikePatternTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function escapes(): array
    {
        return [
            'plain text is left as it is' => ['invoice', 'invoice'],
            'a percent' => ['100%', '100!%'],
            'an underscore' => ['a_b', 'a!_b'],
            'the escape character is escaped with itself' => ['bang!', 'bang!!'],
            'all three, in order' => ['!%_', '!!!%!_'],
            'a backslash is not special' => ['a\\b', 'a\\b'],
            'letters of other languages are not touched' => ['Zoë Łukasz', 'Zoë Łukasz'],
            'nothing' => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function it_escapes_what_a_like_would_read_as_a_pattern(string $typed, string $escaped): void
    {
        $this->assertSame($escaped, LikePattern::escape($typed));
    }

    #[Test]
    public function contains_matches_anywhere_and_starts_with_matches_the_start(): void
    {
        $this->assertSame('%100!%%', LikePattern::contains('100%'));
        $this->assertSame('100!%%', LikePattern::startsWith('100%'));
        $this->assertSame('%%', LikePattern::contains(''), 'an empty text is "anything", as it always was: callers do not search for it');
    }

    #[Test]
    public function the_clause_names_the_escape_character_and_quotes_the_column(): void
    {
        // Quoted the way the connection's own grammar quotes it: backticks on MySQL, double quotes on PostgreSQL and SQLite.
        $grammar = DB::connection()->getQueryGrammar();

        $this->assertNotSame('title', $grammar->wrap('title'), 'the column is quoted');
        $this->assertSame('lower('.$grammar->wrap('title').') like lower(?) escape \'!\'', LikePattern::clause('title'));
        $this->assertSame('lower('.$grammar->wrap('blogs.title').') like lower(?) escape \'!\'', LikePattern::clause('blogs.title'));
        $this->assertNotSame($grammar->wrap('blogs.title'), $grammar->wrap('title'), 'a table name is kept');
    }
}
