<?php

namespace App\Support\Search;

/**
 * What {@see SearchHealth} found, for the ACP card and `metaforge:search:health` (docs/search-index.md).
 */
final readonly class SearchHealthReport
{
    /** Search uses the database, so there is nothing to check. */
    public const UNUSED = 'unused';

    public const HEALTHY = 'healthy';

    /** Meilisearch answers, but something is out of step and a command puts it right. */
    public const ATTENTION = 'attention';

    /** Meilisearch cannot be reached, or refused the key. */
    public const DOWN = 'down';

    /**
     * @param  list<array{name: string, exists: bool, documents: int|null, expected: int, indexing: bool, settings_in_sync: bool|null}>  $indexes
     * @param  list<string>  $problems  What is wrong, and what to do about it.
     * @param  list<string>  $notes  What is worth knowing but is not wrong.
     */
    public function __construct(
        public string $status,
        public string $driver,
        public ?string $host = null,
        public ?string $version = null,
        public bool $circuitOpen = false,
        public ?string $lastSyncedAt = null,
        public ?string $lastReindexedAt = null,
        public int $failedTasks = 0,
        public array $indexes = [],
        public array $problems = [],
        public array $notes = [],
    ) {}

    /**
     * Whether a deploy script may carry on: nothing is wrong (or nothing is in use).
     */
    public function ok(): bool
    {
        return in_array($this->status, [self::UNUSED, self::HEALTHY], true);
    }

    /**
     * @return array{status: string, driver: string, host: string|null, version: string|null, circuit_open: bool, last_synced_at: string|null, last_reindexed_at: string|null, failed_tasks: int, indexes: list<array{name: string, exists: bool, documents: int|null, expected: int, indexing: bool, settings_in_sync: bool|null}>, problems: list<string>, notes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'driver' => $this->driver,
            'host' => $this->host,
            'version' => $this->version,
            'circuit_open' => $this->circuitOpen,
            'last_synced_at' => $this->lastSyncedAt,
            'last_reindexed_at' => $this->lastReindexedAt,
            'failed_tasks' => $this->failedTasks,
            'indexes' => $this->indexes,
            'problems' => $this->problems,
            'notes' => $this->notes,
        ];
    }
}
