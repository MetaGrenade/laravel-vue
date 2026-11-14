<?php

namespace Tests\Unit\Search;

use App\Support\Search\SearchText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The text of a search document comes from one of two methods, and which one is about what the field holds
 * (see the class): `html()` for a rich-text body, `plain()` for text as it was typed.
 */
class SearchTextTest extends TestCase
{
    #[Test]
    public function plain_keeps_what_was_typed_and_takes_out_the_tags_and_the_extra_space(): void
    {
        $this->assertSame('Write &amp; for an ampersand, in HTML', SearchText::plain("  Write &amp; for\n an ampersand, <em>in</em>   HTML "));
        $this->assertSame('Don&#039;t', SearchText::plain('Don&#039;t'), 'an entity that was typed is five characters of text and not an apostrophe');
        $this->assertSame('', SearchText::plain(null));
        $this->assertSame('42', SearchText::plain(42));
    }

    #[Test]
    public function html_gives_the_words_of_a_rich_text_body(): void
    {
        $this->assertSame('Don\'t forget: Q&A one two', SearchText::html('<p>Don&#039;t forget: Q&amp;A</p><ul><li>one</li><li>two</li></ul>'));
        $this->assertSame('', SearchText::html(null));
    }

    #[Test]
    public function both_stop_at_the_limit_given_and_at_the_configured_one(): void
    {
        $this->assertSame('abc', SearchText::plain('abcdef', 3));
        $this->assertSame('abc', SearchText::html('<p>abcdef</p>', 3));
        $this->assertSame('', SearchText::plain('abcdef', 0));
        $this->assertSame('', SearchText::html('abcdef', 0));

        config(['search.documents.max_characters' => 4]);

        $this->assertSame('abcd', SearchText::plain('abcdef'));
        $this->assertSame('abcd', SearchText::html('abcdef'));
    }
}
