<?php

namespace App\Console\Commands;

use App\Support\Search\SearchHealth;
use App\Support\Search\SearchHealthReport;
use Illuminate\Console\Command;

/**
 * Says whether Meilisearch is reachable and holds what the database says it should, and fails when something is wrong, so a deploy
 * script can stop on it (docs/search-index.md). It only reads.
 */
class SearchHealthCommand extends Command
{
    protected $signature = 'metaforge:search:health
        {--json : Print the report as JSON}';

    protected $description = 'Check that Meilisearch is reachable, configured and holds the documents it should';

    public function handle(SearchHealth $health): int
    {
        $report = $health->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report->ok() ? self::SUCCESS : self::FAILURE;
        }

        if ($report->status === SearchHealthReport::UNUSED) {
            $this->components->info('Search uses the database (SEARCH_DRIVER='.$report->driver.'). Meilisearch is not in use, so there is nothing to check.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Host', (string) $report->host);
        $this->components->twoColumnDetail('Version', $report->version ?? 'unknown');
        $this->components->twoColumnDetail('Settings last pushed', $report->lastSyncedAt ?? 'never recorded');
        $this->components->twoColumnDetail('Documents last rebuilt', $report->lastReindexedAt ?? 'never recorded');

        if ($report->indexes !== []) {
            $this->table(
                ['Index', 'Documents', 'Should be', 'Settings', 'Updating'],
                array_map(fn (array $index) => [
                    $index['name'],
                    $index['exists'] ? (string) $index['documents'] : 'missing',
                    (string) $index['expected'],
                    $index['settings_in_sync'] === null ? '-' : ($index['settings_in_sync'] ? 'as coded' : 'different'),
                    $index['indexing'] ? 'yes' : 'no',
                ], $report->indexes),
            );
        }

        foreach ($report->notes as $note) {
            $this->components->info($note);
        }

        foreach ($report->problems as $problem) {
            $this->components->error($problem);
        }

        if ($report->ok()) {
            $this->components->info('Meilisearch is healthy.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
