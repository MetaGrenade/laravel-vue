<?php

namespace App\Support\Search;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When the Meilisearch settings were last pushed and the documents last rebuilt, and where the last rebuild began, so the ACP health
 * card can say how old they are and count what Meilisearch refused of the rebuild (docs/search-index.md). Kept in `system_settings`,
 * written only by `metaforge:search:sync` and `metaforge:search:reindex`.
 *
 * A rebuild is noted twice. Where it BEGAN is noted before it does anything, as a Meilisearch task number: the updates it sends are
 * done afterwards and may be refused, so the refusals are the tasks from that number on. That it is DONE is noted only once those tasks
 * have finished and none was refused.
 */
final class SearchIndexState
{
    private const SYNCED = 'search.settings_synced_at';

    private const REINDEXED = 'search.reindexed_at';

    private const REINDEX_FROM_TASK = 'search.reindex_from_task';

    public static function recordSync(): void
    {
        self::write(self::SYNCED, now()->toIso8601String());
    }

    /**
     * A rebuild is about to send its first update; this is the task number Meilisearch will give it, or one before it.
     */
    public static function recordReindexBegan(int $fromTask): void
    {
        self::write(self::REINDEX_FROM_TASK, $fromTask);
    }

    public static function recordReindex(): void
    {
        self::write(self::REINDEXED, now()->toIso8601String());
    }

    public static function lastSyncedAt(): ?Carbon
    {
        return self::read(self::SYNCED);
    }

    public static function lastReindexedAt(): ?Carbon
    {
        return self::read(self::REINDEXED);
    }

    /**
     * The task number the last rebuild began from, or null when none is on record.
     */
    public static function lastReindexFromTask(): ?int
    {
        $value = SystemSetting::get(self::REINDEX_FROM_TASK);

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * A note about the index is a convenience: a database that cannot take it must not fail a command that has done its work.
     */
    private static function write(string $key, string|int $value): void
    {
        try {
            SystemSetting::set($key, $value);
        } catch (Throwable $exception) {
            Log::warning('Could not note when the search index was updated: '.$exception->getMessage());
        }
    }

    private static function read(string $key): ?Carbon
    {
        $value = SystemSetting::get($key);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
