<?php

namespace App\Jobs;

use App\Models\TebexSync;
use App\Models\User;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexClient;
use App\Payments\Tebex\TebexException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes the copy of the Tebex catalogue. A failure is recorded on the run (which the ACP shows), not thrown: a
 * store that is down for an hour must not fill the failed-jobs table.
 */
class SyncTebexCatalogue implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $trigger = TebexSync::SCHEDULE,
        public readonly ?int $userId = null,
    ) {}

    public function handle(TebexCatalogue $catalogue, TebexClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        try {
            $catalogue->sync($this->trigger, $this->userId !== null ? User::query()->find($this->userId) : null);
        } catch (TebexException) {
            // Another run is in progress; it will do the work.
        }
    }
}
