<?php

namespace Tests;

use App\Support\Ownership;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function tearDown(): void
    {
        // Held in a static, so one test giving somebody a second owner must not carry over to the next.
        Ownership::flush();

        parent::tearDown();
    }
}
