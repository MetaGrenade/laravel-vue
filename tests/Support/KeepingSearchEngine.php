<?php

namespace Tests\Support;

use Laravel\Scout\Engines\NullEngine;
use RuntimeException;

/**
 * An index that keeps the documents it is given, and what it was asked to remove.
 *
 * Scout's collection engine reads the database, so it cannot show a document that is left over or one that carries an old name: this one
 * keeps what it was given. Register it with `EngineManager::extend()` (a local variable in the closure, not `$this`: Laravel binds the
 * closure it is given to its own manager) and set `scout.driver` to the name.
 */
class KeepingSearchEngine extends NullEngine
{
    /** @var array<class-string, array<int, array<string, mixed>>> */
    public array $documents = [];

    public int $updates = 0;

    public int $deletes = 0;

    /** @var list<int> The size of each removal that was asked for. */
    public array $batches = [];

    /** @var list<int> Every key it was asked to remove. */
    public array $removed = [];

    public ?RuntimeException $failWith = null;

    public bool $failOnce = false;

    public function update($models): void
    {
        foreach ($models as $model) {
            $this->updates++;
            $this->documents[$model::class][(int) $model->getScoutKey()] = $model->toSearchableArray();
        }
    }

    public function delete($models): void
    {
        $this->deletes += count($models);
        $this->batches[] = count($models);

        if ($this->failWith !== null) {
            $failure = $this->failWith;

            if ($this->failOnce) {
                $this->failWith = null;
            }

            throw $failure;
        }

        foreach ($models as $model) {
            $this->removed[] = (int) $model->getScoutKey();
            unset($this->documents[$model::class][(int) $model->getScoutKey()]);
        }
    }
}
