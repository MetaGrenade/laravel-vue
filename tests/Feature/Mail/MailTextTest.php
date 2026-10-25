<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\AccountBanned;
use App\Notifications\BanLifted;
use App\Support\Mail\MailText;
use Illuminate\Mail\Markdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Text somebody typed, in an email (docs/email.md): the lines of a notification are read as Markdown, so it has to be put in as the
 * words it is.
 */
class MailTextTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function awkwardText(): array
    {
        return [
            'a link' => ['Read [the rules](https://evil.example/login) first'],
            'a bare address' => ['Go to https://evil.example/login or mail me@evil.example'],
            'emphasis' => ['This is *very* __bad__ and ~~deleted~~'],
            'code' => ['Run `rm -rf` now'],
            'a tag' => ['<script>alert(1)</script> <img src=x onerror=alert(1)>'],
            'a heading and a list' => ["# Title\n- one\n- two\n1. three"],
            'a table' => ["| a | b |\n|---|---|\n| 1 | 2 |"],
            'a backslash' => ['C:\\Users\\me and a trailing \\'],
            'an image' => ['![x](https://evil.example/pixel.png)'],
            'an html entity' => ['Tom &amp; Jerry &lt;3'],
            'a backslash before a star' => ['\\*not emphasis* but *this is*'],
            'a nested quotation' => ['> a quotation of its own'],
            'a fence made of tildes' => ["~~~\ncode\n~~~"],
            'a list made of plus signs' => ["+ one\n+ two"],
            'a link written as a reference' => ["[click]: https://evil.example/login\nSee [click] and [other][click]"],
            'a heading underlined with equals signs' => ["Title\n====="],
            'a heading underlined with dashes' => ["Title\n-----"],
            'code made by indenting' => ['    indented like code'],
        ];
    }

    #[Test]
    #[DataProvider('awkwardText')]
    public function text_in_a_quotation_comes_out_as_the_words_it_is(string $text): void
    {
        $html = (string) Markdown::parse(MailText::quoted($text));

        // Nothing it held became a link, an image, a tag or any other element but the quotation around it.
        $this->assertSame(1, $this->elements($html, 'blockquote'));
        foreach (['a', 'img', 'script', 'em', 'strong', 'code', 'h1', 'ul', 'ol', 'li', 'table', 'del'] as $tag) {
            $this->assertSame(0, $this->elements($html, $tag), "an element <$tag> came of: $text");
        }

        // And what is left, read as text, is what was typed (the lines of it, with the spaces between words kept).
        $shown = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $this->assertSame($this->words($text), $this->words($shown));
    }

    #[Test]
    #[DataProvider('awkwardText')]
    public function text_in_a_sentence_comes_out_as_the_words_it_is(string $text): void
    {
        $html = (string) Markdown::parse('About '.MailText::inline($text).' today.');

        foreach (['a', 'img', 'script', 'em', 'strong', 'code', 'h1', 'ul', 'li', 'table', 'del', 'blockquote'] as $tag) {
            $this->assertSame(0, $this->elements($html, $tag), "an element <$tag> came of: $text");
        }

        $this->assertSame($this->words('About '.$text.' today.'), $this->words(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    #[Test]
    public function a_quotation_keeps_its_lines_and_an_inline_one_is_a_single_line(): void
    {
        $this->assertSame("> one\n> two\n> three", MailText::quoted("one\r\ntwo\rthree"));
        $this->assertSame('one two three', MailText::inline("one\n  two\t\tthree "));
        $this->assertSame('> hello', MailText::quoted("  hello  \n"));
    }

    #[Test]
    public function every_character_markdown_could_read_is_put_after_a_backslash(): void
    {
        // Some are not needed by the Markdown Laravel reads emails with, and are here for a site that adds to it (`mail.markdown.extensions`).
        $this->assertSame('> \\\\\\`\\*\\_\\{\\}\\[\\]\\(\\)\\#\\+\\-\\.\\!\\|\\<\\>\\~\\&\\:\\@\\=', MailText::quoted('\\`*_{}[]()#+-.!|<>~&:@='));
    }

    #[Test]
    public function the_notices_of_a_ban_show_the_reason_as_text(): void
    {
        $member = new User(['nickname' => 'Ada']);
        $reason = 'See [appeals](https://evil.example/appeal) *now*';

        foreach ([new AccountBanned($reason, now()->addDay()), new BanLifted(false, $reason)] as $notice) {
            $html = (string) $notice->toMail($member)->render();

            $this->assertStringNotContainsString('href="https://evil.example', $html, $notice::class);
            $this->assertStringContainsString('https://evil.example/appeal', $html, $notice::class);
        }
    }

    #[Test]
    public function a_ban_email_says_until_when_and_a_permanent_one_does_not(): void
    {
        $member = new User(['nickname' => 'Ada', 'timezone' => 'UTC']);

        $mail = (new AccountBanned('Spam', now()->addDays(3)->setTime(23, 59, 59)))->toMail($member);
        $this->assertSame('Your account has been suspended', $mail->subject);
        $until = (string) $mail->render();
        $this->assertStringContainsString('suspended your account until', $until);
        $this->assertStringContainsString(now()->addDays(3)->format('Y'), $until);

        $permanent = (new AccountBanned('Spam'))->toMail($member);
        $this->assertSame('Your account has been banned', $permanent->subject);
        $forGood = (string) $permanent->render();
        $this->assertStringContainsString('has banned your account', $forGood);
        $this->assertStringNotContainsString('suspended until', $forGood);
    }

    private function elements(string $html, string $tag): int
    {
        libxml_use_internal_errors(true);
        $page = new \DOMDocument;
        $page->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>');

        return $page->getElementsByTagName($tag)->length;
    }

    /**
     * The words of a text, whatever the layout: for comparing what was typed with what is shown.
     */
    private function words(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
