<?php

namespace App\Console\Commands;

use App\Models\TebexSync;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexException;
use Illuminate\Console\Command;

class TebexSyncCommand extends Command
{
    protected $signature = 'tebex:sync';

    protected $description = 'Refresh the shop\'s copy of the Tebex store\'s categories and packages';

    public function handle(TebexCatalogue $catalogue): int
    {
        try {
            $run = $catalogue->sync(TebexSync::COMMAND);
        } catch (TebexException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($run->status !== TebexSync::OK) {
            $this->components->error($run->error ?? 'The synchronisation failed.');

            return self::FAILURE;
        }

        $rows = [];

        // In this order, not the order the counts come back from the database in (MySQL sorts an object's keys).
        foreach (['categories', 'packages'] as $what) {
            $counts = (array) ($run->counts[$what] ?? []);

            $rows[] = [ucfirst($what), $counts['created'] ?? 0, $counts['updated'] ?? 0, $counts['unchanged'] ?? 0, $counts['removed'] ?? 0];
        }

        $this->table(['', 'Added', 'Changed', 'Unchanged', 'No longer listed'], $rows);

        return self::SUCCESS;
    }
}
