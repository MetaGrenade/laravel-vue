<?php

namespace Tests\Unit\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use App\Models\Product;
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
            [Blog::class, ForumThread::class, Faq::class, Product::class],
            array_keys($settings),
        );

        $this->assertSame(
            ['title', 'excerpt', 'body', 'author'],
            $settings[Blog::class]['searchableAttributes'],
        );
        $this->assertSame(
            ['title', 'excerpt', 'replies', 'author'],
            $settings[ForumThread::class]['searchableAttributes'],
        );
        $this->assertSame(
            ['question', 'answer', 'category'],
            $settings[Faq::class]['searchableAttributes'],
        );
        $this->assertSame(
            ['name', 'description', 'brand'],
            $settings[Product::class]['searchableAttributes'],
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

    #[Test]
    public function searchable_models_use_the_same_index_name_as_settings_sync(): void
    {
        config(['scout.prefix' => 'site_']);

        $this->assertSame('site_blogs', (new Blog)->indexableAs());
        $this->assertSame('site_forum_threads', (new ForumThread)->indexableAs());
        $this->assertSame('site_faqs', (new Faq)->indexableAs());
        $this->assertSame('site_products', (new Product)->indexableAs());
    }
}
