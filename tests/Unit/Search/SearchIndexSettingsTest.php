<?php

namespace Tests\Unit\Search;

use App\Support\Search\SearchIndexSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchIndexSettingsTest extends TestCase
{
    #[Test]
    public function it_defines_settings_for_each_search_index(): void
    {
        $settings = SearchIndexSettings::forScout();

        $this->assertSame(
            ['blogs', 'forum_threads', 'faqs', 'products'],
            array_keys($settings),
        );

        $this->assertSame(
            ['title', 'excerpt', 'body', 'author'],
            $settings['blogs']['searchableAttributes'],
        );
        $this->assertSame(
            ['title', 'excerpt', 'replies', 'author'],
            $settings['forum_threads']['searchableAttributes'],
        );
        $this->assertSame(
            ['question', 'answer', 'category'],
            $settings['faqs']['searchableAttributes'],
        );
        $this->assertSame(
            ['name', 'description', 'brand'],
            $settings['products']['searchableAttributes'],
        );

        foreach ($settings as $index) {
            $this->assertSame([], $index['filterableAttributes']);
            $this->assertSame([], $index['sortableAttributes']);
            $this->assertTrue($index['typoTolerance']['enabled']);
            $this->assertSame(4, $index['typoTolerance']['minWordSizeForTypos']['oneTypo']);
            $this->assertSame(8, $index['typoTolerance']['minWordSizeForTypos']['twoTypos']);
            $this->assertSame(
                ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
                $index['rankingRules'],
            );
        }
    }
}
