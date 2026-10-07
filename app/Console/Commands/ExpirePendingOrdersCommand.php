<?php

namespace App\Console\Commands;

use App\Support\Commerce\PendingOrderExpirer;
use Illuminate\Console\Command;

class ExpirePendingOrdersCommand extends Command
{
    protected $signature = 'commerce:expire-orders';

    protected $description = 'Cancel unpaid orders whose payment window has passed and release their stock';

    public function handle(PendingOrderExpirer $expirer): int
    {
        $result = $expirer->run();

        $this->table(['Result', 'Orders'], collect($result)->map(fn (int $count, string $name) => [$name, $count])->values()->all());

        return self::SUCCESS;
    }
}
