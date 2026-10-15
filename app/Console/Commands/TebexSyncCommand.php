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

        foreach ((array) $run->counts as $what => $counts) {
            $rows[] = [ucfirst((string) $what), $counts['created'] ?? 0, $counts['updated'] ?? 0, $counts['unchanged'] ?? 0, $counts['removed'] ?? 0];
        }

        $this->table(['', 'Added', 'Changed', 'Unchanged', 'No longer listed'], $rows);

        return self::SUCCESS;
    }
}
