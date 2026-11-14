<?php

namespace App\Support\Text;

use Illuminate\Support\Str;

/**
 * The words of some stored HTML, as a reader gets them: one line of text for a notice, a preview, a search result or a feed.
 *
 * The sanitiser stores `Don't` as `Don&#039;t` and `Q&A` as `Q&amp;A`, and the editor puts no space between `</p><p>` or
 * `</li><li>`. `strip_tags` alone gives `Don&#039;tQ&amp;A` run into the next paragraph, which is what a member read in a
 * mention email, the bell, a search result and a quoted reply. The text is made in this order, and it is the order that matters:
 *
 * 1. the start and the end of a block (paragraph, list item, heading, line break...) become a space, so two paragraphs are two words;
 * 2. the tags go (inline ones, like `<strong>`, leave no space, so `<b>un</b>likely` is still one word);
 * 3. the entities are decoded, last, so `&lt;b&gt;` the member typed stays the characters `<b>` and is not taken for a tag;
 * 4. runs of white space (a non-breaking space too) are one space.
 *
 * What comes out is text, not markup: whatever puts it in a page has to escape it, as `{{ }}` and `e()` do.
 */
final class PlainText
{
    /**
     * Where a reader starts a new line: the start or the end of a block (the start counts as well, for text that runs straight into a
     * nested list or a quote), and a line break or a rule. The forum keeps paragraphs, lists, quotes and code blocks; a blog post keeps
     * the rest of these (headings, tables, sections). The name is followed by a space, a slash or the > so that <brand> is not a break.
     */
    private const BLOCK_TAG = '~</?(?:p|div|li|ul|ol|dl|dt|dd|h[1-6]|hgroup|header|footer|main|nav|aside|section|article|blockquote|pre|figure|figcaption|details|summary|table|caption|thead|tbody|tfoot|tr|td|th|br|hr)(?=[\s/>])[^>]*>~i';

    public static function of(mixed $html): string
    {
        $text = (string) $html;
        $text = preg_replace(self::BLOCK_TAG, ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** The text, cut at `$limit` (with the "..." `Str::limit` puts at a cut). */
    public static function excerpt(mixed $html, int $limit): string
    {
        return Str::limit(self::of($html), $limit);
    }
}
