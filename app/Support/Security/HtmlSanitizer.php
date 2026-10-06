<?php

namespace App\Support\Security;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises user-supplied rich text before it is stored.
 *
 * Content is rendered with `v-html` on the frontend, so anything that reaches
 * the database must already be safe to inject into the page.
 */
class HtmlSanitizer
{
    private ?SymfonyHtmlSanitizer $forum = null;

    private ?SymfonyHtmlSanitizer $article = null;

    /**
     * Community content (forum threads and replies). Only the markup that the
     * RichTextEditor can produce is kept. `class` and `style` are dropped so
     * posts cannot restyle or overlay the surrounding interface.
     */
    public function forum(?string $html): string
    {
        $this->forum ??= new SymfonyHtmlSanitizer(
            $this->baseConfig()
                ->withMaxInputLength(100_000)
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('strong')
                ->allowElement('b')
                ->allowElement('em')
                ->allowElement('i')
                ->allowElement('s')
                ->allowElement('strike')
                ->allowElement('del')
                ->allowElement('u')
                ->allowElement('mark')
                ->allowElement('code')
                ->allowElement('pre')
                ->allowElement('blockquote')
                ->allowElement('ul')
                ->allowElement('ol', ['start'])
                ->allowElement('li')
                ->allowElement('hr')
                ->allowElement('span', ['data-type', 'data-id', 'data-nickname', 'data-label', 'data-profile-url'])
                ->allowElement('a', ['href', 'title', 'data-type', 'data-id', 'data-nickname', 'data-label', 'data-profile-url'])
                ->forceAttribute('a', 'rel', 'nofollow ugc noopener noreferrer')
        );

        return $this->forum->sanitize((string) $html);
    }

    /**
     * Staff-authored long-form content (blog posts). Allows the full set of
     * safe structural elements, images and tables, but never scripts, event
     * handlers, inline styles or embedded frames.
     */
    public function article(?string $html): string
    {
        $this->article ??= new SymfonyHtmlSanitizer(
            $this->baseConfig()
                ->withMaxInputLength(1_000_000)
                ->allowSafeElements()
                ->allowRelativeMedias()
                ->allowMediaSchemes(['http', 'https'])
                ->dropAttribute('style', '*')
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
        );

        return $this->article->sanitize((string) $html);
    }

    private function baseConfig(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            // Unknown wrappers (e.g. pasted <div>s) are unwrapped, keeping their text.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks();

        // ...but executable or embedded content is removed entirely.
        foreach (['script', 'style', 'template', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base'] as $element) {
            $config = $config->dropElement($element);
        }

        return $config;
    }
}
