<?php

namespace Tests\Unit\Support;

use App\Support\Security\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function xssPayloads(): array
    {
        return [
            'script tag' => ['<p>hi</p><script>alert(1)</script>'],
            'img onerror' => ['<img src="x" onerror="alert(1)">'],
            'event handler on allowed element' => ['<p onclick="alert(1)">click</p>'],
            'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
            'data uri link' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'style tag' => ['<style>body{display:none}</style>'],
            'inline style' => ['<p style="position:fixed;inset:0">overlay</p>'],
            'form' => ['<form action="https://evil.example"><input name="password"></form>'],
        ];
    }

    #[DataProvider('xssPayloads')]
    public function test_forum_policy_neutralises_xss_payloads(string $payload): void
    {
        $clean = (new HtmlSanitizer)->forum($payload);

        $this->assertDoesNotMatchRegularExpression('/<(script|iframe|svg|style|form|input|img)\b/i', $clean);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        $this->assertStringNotContainsStringIgnoringCase('data:text/html', $clean);
        $this->assertStringNotContainsStringIgnoringCase('style=', $clean);
    }

    #[DataProvider('xssPayloads')]
    public function test_article_policy_neutralises_xss_payloads(string $payload): void
    {
        $clean = (new HtmlSanitizer)->article($payload);

        $this->assertDoesNotMatchRegularExpression('/<(script|iframe|svg|style|form|input)\b/i', $clean);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        $this->assertStringNotContainsStringIgnoringCase('style=', $clean);
    }

    public function test_forum_policy_keeps_editor_formatting(): void
    {
        $html = '<p><strong>Bold</strong> <em>italic</em> <s>gone</s> <code>x</code></p>'
            .'<blockquote><p>quoted</p></blockquote><ul><li><p>one</p></li></ul><ol><li>two</li></ol>'
            .'<pre><code>let a = 1;</code></pre><hr>';

        $clean = (new HtmlSanitizer)->forum($html);

        foreach (['<strong>', '<em>', '<s>', '<code>', '<blockquote>', '<ul>', '<ol>', '<li>', '<pre>', '<hr'] as $tag) {
            $this->assertStringContainsString($tag, $clean);
        }
    }

    public function test_forum_policy_keeps_mentions_but_strips_classes(): void
    {
        $html = '<p>Hi <a data-type="mention" data-id="5" data-nickname="Jane" data-profile-url="/users/5" '
            .'class="fixed inset-0 z-50" href="/users/5">@Jane</a></p>';

        $clean = (new HtmlSanitizer)->forum($html);

        $this->assertStringContainsString('data-type="mention"', $clean);
        $this->assertStringContainsString('data-nickname="Jane"', $clean);
        $this->assertStringContainsString('href="/users/5"', $clean);
        $this->assertStringContainsString('rel="nofollow ugc noopener noreferrer"', $clean);
        $this->assertStringNotContainsString('class=', $clean);
    }

    public function test_unknown_wrappers_are_unwrapped_without_losing_text(): void
    {
        $clean = (new HtmlSanitizer)->forum('<div><p>Kept <font>text</font></p></div>');

        $this->assertStringContainsString('Kept', $clean);
        $this->assertStringContainsString('text', $clean);
        $this->assertStringNotContainsString('<div', $clean);
        $this->assertStringNotContainsString('<font', $clean);
    }

    public function test_article_policy_allows_headings_images_and_tables(): void
    {
        $html = '<h2>Title</h2><p><img src="https://cdn.example.com/a.png" alt="A"></p>'
            .'<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody></table>';

        $clean = (new HtmlSanitizer)->article($html);

        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('src="https://cdn.example.com/a.png"', $clean);
        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('<td>D</td>', $clean);
    }
}
