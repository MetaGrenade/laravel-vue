<?php

namespace App\Support\Forum;

use App\Support\Text\PlainText;
use Dom\HTMLDocument;

/**
 * Who a post names, read from the HTML as it is stored. A nickname is anything up to 255 characters that nobody else has (`Ada Lovelace`, `Zoë`,
 * `Łukasz`, `Ada.`), and there are two ways a post names one:
 *
 *  - **The editor's node.** Choosing a member from the list makes `<a data-type="mention" data-nickname="Ada Lovelace">@Ada Lovelace</a>` (a `span`
 *    when there is no profile link), which carries the exact name. It is believed when what it shows is the name it carries, so that a reader who
 *    sees `@Bob` is not told a different member; one that does not match, or has no name, is read as the text it shows. Its text is then taken out
 *    of the rest, so that the first word of `Ada Lovelace` is not read a second time as a member called `Ada`.
 *  - **`@nickname` typed in the text** (an API post, or one typed by hand), which ends at the first character a name does not have: letters and digits
 *    of any language, marks, `_`, `.` and `-`, 2 to 50 of them. A name with a space can only be named by the editor's node. The `.` or `-` that ends a
 *    sentence (`Thanks @Ada.`) is not told from one that ends a name (`Ada.`), so every shorter name it can be is a candidate, longest first, and
 *    the longest that is a member is who is meant.
 *
 * The text is made with `PlainText`, so that a block is a space: `<p>Thanks</p><p>@Ada look</p>` has the at sign after a space and not after the *s*.
 */
final class MentionParser
{
    private const TYPED = '/(?<![\w@])@([\p{L}\p{N}\p{M}_.\-]{2,50})/u';

    /**
     * @return array{named: list<string>, typed: list<non-empty-list<string>>}
     */
    public function parse(string $html): array
    {
        ['names' => $named, 'html' => $rest] = $this->nodes($html);

        preg_match_all(self::TYPED, PlainText::of($rest), $matches);

        return [
            'named' => $named,
            'typed' => array_map($this->candidates(...), $matches[1]),
        ];
    }

    /**
     * The names the editor wrote into the post, and the HTML without them.
     *
     * @return array{names: list<string>, html: string}
     */
    private function nodes(string $html): array
    {
        // The sanitiser writes every attribute with double quotes, and most posts have no mention node: those are not parsed.
        if (! str_contains($html, 'data-type="mention"')) {
            return ['names' => [], 'html' => $html];
        }

        $document = HTMLDocument::createFromString('<body>'.$html.'</body>', LIBXML_NOERROR);
        $names = [];

        foreach ($document->querySelectorAll('[data-type="mention"]') as $node) {
            $name = trim((string) $node->getAttribute('data-nickname'));

            if ($name === '' || $this->collapse($node->textContent) !== $this->collapse('@'.$name)) {
                continue;
            }

            $names[] = $name;
            $node->replaceWith(' ');
        }

        return ['names' => $names, 'html' => $document->body->innerHTML];
    }

    /**
     * `Ada...` is `Ada...`, `Ada..`, `Ada.` or `Ada`, longest first, and never shorter than 2 characters.
     *
     * @return non-empty-list<string>
     */
    private function candidates(string $token): array
    {
        $candidates = [$token];

        while (mb_strlen($token) > 2 && in_array(mb_substr($token, -1), ['.', '-'], true)) {
            $token = mb_substr($token, 0, -1);
            $candidates[] = $token;
        }

        return $candidates;
    }

    private function collapse(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
