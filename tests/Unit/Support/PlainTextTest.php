<?php

namespace Tests\Unit\Support;

use App\Support\Security\HtmlSanitizer;
use App\Support\Text\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PlainTextTest extends TestCase
{
    #[Test]
    public function the_entities_the_sanitiser_stores_are_the_characters_a_member_typed(): void
    {
        $this->assertSame('Don\'t forget: Q&A with R&D, say "hi" & 5 < 6', PlainText::of('<p>Don&#039;t forget: Q&amp;A with R&amp;D, say &#34;hi&#34; &amp; 5 &lt; 6</p>'));
        $this->assertSame('say "hi" and \'bye\'', PlainText::of('say &quot;hi&quot; and &apos;bye&apos;'));
        $this->assertSame('café — “smart”', PlainText::of('caf&eacute; &mdash; &ldquo;smart&rdquo;'));
    }

    #[Test]
    public function what_a_member_typed_as_markup_is_text_and_not_removed(): void
    {
        // They wrote the characters <b>, which are stored as &lt;b&gt;: not a tag, so not for strip_tags to take out.
        $this->assertSame('Use <b>bold</b> for emphasis', PlainText::of('<p>Use &lt;b&gt;bold&lt;/b&gt; for emphasis</p>'));
        $this->assertSame('a <script>alert(1)</script> b', PlainText::of('a &lt;script&gt;alert(1)&lt;/script&gt; b'));
    }

    #[Test]
    public function an_entity_is_decoded_once(): void
    {
        // They wrote the characters &#039; and the sanitiser stored them as &amp;#039;: one step back is &#039;, not an apostrophe.
        $this->assertSame('write &#039; for an apostrophe', PlainText::of('<p>write &amp;#039; for an apostrophe</p>'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blocks(): array
    {
        return [
            'paragraphs' => ['<p>First.</p><p>Second.</p>', 'First. Second.'],
            'list items' => ['<ul><li>one</li><li>two</li></ul><ol><li>three</li></ol>', 'one two three'],
            'headings and a paragraph' => ['<h2>Title</h2><p>Text</p>', 'Title Text'],
            'a quote and a paragraph' => ['<blockquote><p>Said</p></blockquote><p>Reply</p>', 'Said Reply'],
            'line breaks' => ['one<br>two<br/>three<br />four', 'one two three four'],
            'a rule' => ['above<hr>below', 'above below'],
            'a code block' => ['<pre>line one</pre><p>after</p>', 'line one after'],
            'a table' => ['<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>', 'a b c'],
            'tags in capitals' => ['<P>One</P><BR><LI>Two</LI>', 'One Two'],
        ];
    }

    #[Test]
    #[DataProvider('blocks')]
    public function the_end_of_a_block_is_a_space(string $html, string $text): void
    {
        $this->assertSame($text, PlainText::of($html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockElements(): array
    {
        $tags = [
            'p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup', 'header', 'footer', 'main', 'nav', 'aside',
            'section', 'article', 'blockquote', 'pre', 'figure', 'figcaption', 'details', 'summary', 'table', 'caption', 'thead', 'tbody', 'tfoot',
            'tr', 'td', 'th',
        ];

        return array_combine($tags, array_map(fn (string $tag) => [$tag], $tags));
    }

    #[Test]
    #[DataProvider('blockElements')]
    public function every_element_a_reader_sees_as_a_block_ends_a_word(string $tag): void
    {
        $this->assertSame('one two', PlainText::of("<{$tag}>one</{$tag}>two"));
        $this->assertSame('one two', PlainText::of("<{$tag}>one</{$tag} >two"), 'a space before the > of a closing tag');
    }

    #[Test]
    public function the_start_of_a_block_ends_a_word_as_well_as_its_end(): void
    {
        // A nested list: the text of an item runs straight into the list inside it.
        $this->assertSame('item sub more', PlainText::of('<ul><li>item<ul><li>sub</li></ul></li><li>more</li></ul>'));
        // Text that runs into a quote, and a paragraph that was never closed.
        $this->assertSame('after said', PlainText::of('after<blockquote>said</blockquote>'));
        $this->assertSame('one two', PlainText::of('<p>one<p>two'));
        // A block with attributes.
        $this->assertSame('three four', PlainText::of('<ol start="3">three<li class="x">four</li></ol>'));
    }

    #[Test]
    public function a_line_break_with_attributes_is_a_line_break(): void
    {
        $this->assertSame('one two', PlainText::of('one<br class="x">two'));
        $this->assertSame('one two', PlainText::of('one<hr class="x">two'));
    }

    #[Test]
    public function a_tag_inside_a_line_leaves_the_word_whole(): void
    {
        $this->assertSame('an unlikely story, see this.', PlainText::of('<p>an <b>un</b>likely <em>story</em>, see <a href="https://example.com">this</a>.</p>'));
        $this->assertSame('ab', PlainText::of('a<brand>b'), 'a tag that only starts with the letters br is not a line break');
    }

    #[Test]
    public function runs_of_white_space_and_non_breaking_spaces_are_one_space(): void
    {
        $this->assertSame('a b c d', PlainText::of("  a \n\n\t b&nbsp;&nbsp;c\u{00A0}\u{00A0}d  "));
        $this->assertSame('a b', PlainText::of('<p>a</p>'."\n".'<p></p>'."\n".'<p>b</p>'));
    }

    #[Test]
    public function nothing_in_gives_nothing_out(): void
    {
        $this->assertSame('', PlainText::of(null));
        $this->assertSame('', PlainText::of(''));
        $this->assertSame('', PlainText::of('<p></p><p>&nbsp;</p>'));
        $this->assertSame('42', PlainText::of(42));
    }

    #[Test]
    public function a_picture_or_a_script_leaves_no_word(): void
    {
        $this->assertSame('before after', PlainText::of('before <img src="a.png" alt="x"> after'));
    }

    #[Test]
    public function it_reads_what_the_sanitiser_made_of_it(): void
    {
        $stored = (new HtmlSanitizer)->forum("<p>Don't forget: Q&A with R&D, say \"hi\" & 5 &lt; 6</p><p>Second paragraph</p><ul><li>one</li><li>two</li></ul>");

        $this->assertStringContainsString('&#039;', $stored, 'the sanitiser stores the apostrophe as an entity');
        $this->assertSame('Don\'t forget: Q&A with R&D, say "hi" & 5 < 6 Second paragraph one two', PlainText::of($stored));
    }

    #[Test]
    public function an_excerpt_is_cut_where_the_limit_says(): void
    {
        $this->assertSame('Don\'t panic', PlainText::excerpt('<p>Don&#039;t panic</p>', 20));
        $this->assertSame('Don\'t pa...', PlainText::excerpt('<p>Don&#039;t panic</p>', 8), 'counted in the characters a reader sees, not in the entity');
        $this->assertSame('', PlainText::excerpt(null, 10));
    }
}
