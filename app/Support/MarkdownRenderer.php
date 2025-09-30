<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer
{
    private static ?MarkdownConverter $converter = null;

    private static function converter(): MarkdownConverter
    {
        if (self::$converter === null) {
            $config = [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 20,
            ];

            $environment = new Environment($config);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
            $environment->addExtension(new AutolinkExtension());
            $environment->addExtension(new TableExtension());
            $environment->addExtension(new DisallowedRawHtmlExtension());

            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter;
    }

    public static function convert(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        return self::converter()->convert($markdown)->getContent();
    }
}
