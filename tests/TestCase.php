<?php

namespace Tests;

use App\Support\Ownership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var list<string> The relations this test loaded one model at a time out of several, as "Model::relation". */
    protected array $lazyLoads = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The app only logs a lazy load, and only locally (AppServiceProvider), so a page that asks one query per row goes unnoticed.
        // In the suite it is a failure: it throws where it happens, and is noted so that a catch that swallows the exception still
        // fails the test. This is set after the app is built because building it sets the app's own handler.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation, \Throwable $violation): never {
            $this->lazyLoads[] = class_basename($model).'::'.$relation;

            throw $violation;
        });
    }

    protected function tearDown(): void
    {
        // Held in a static, so one test giving somebody a second owner must not carry over to the next.
        Ownership::flush();

        $lazyLoads = $this->lazyLoads;
        $this->lazyLoads = [];

        parent::tearDown();

        if ($lazyLoads !== []) {
            $this->fail('Loaded a relation one model at a time instead of for the whole list: '.implode(', ', array_unique($lazyLoads)).'. Eager load it (with(), load()) where the list is fetched; see docs/query-budgets.md.');
        }
    }
}
