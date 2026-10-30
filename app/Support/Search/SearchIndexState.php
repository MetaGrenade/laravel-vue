<?php

namespace App\Support\Search;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When the Meilisearch settings were last pushed and the documents last rebuilt, so the ACP health card can say how old they are
 * (docs/search-index.md). Kept in `system_settings`, written only by `metaforge:search:sync` and `metaforge:search:reindex`.
 */
final class SearchIndexState
{
    private const SYNCED = 'search.settings_synced_at';

    private const REINDEXED = 'search.reindexed_at';

    public static function recordSync(): void
    {
        self::write(self::SYNCED);
    }

    public static function recordReindex(): void
    {
        self::write(self::REINDEXED);
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
     * A note about the index is a convenience: a database that cannot take it must not fail a command that has done its work.
     */
    private static function write(string $key): void
    {
        try {
            SystemSetting::set($key, now()->toIso8601String());
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
