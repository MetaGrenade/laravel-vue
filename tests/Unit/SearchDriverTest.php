<?php

namespace Tests\Unit;

use Tests\TestCase;

class SearchDriverTest extends TestCase
{
    public function test_the_database_driver_does_not_talk_to_meilisearch(): void
    {
        $this->assertSame('database', config('search.driver'));
        $this->assertSame('null', config('scout.driver'));
        $this->assertFalse(config('scout.queue'));
    }
}
