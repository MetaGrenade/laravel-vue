<?php

namespace App\Console\Commands;

use App\Support\Privacy\MemberLeftovers;
use Illuminate\Console\Command;

class ForgetDeletedMembersLeftoversCommand extends Command
{
    protected $signature = 'metaforger:members:forget-leftovers {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Delete what accounts deleted by an earlier version left behind (API keys and their request log, notifications, saved addresses, entitlements, sessions, an unused password reset, Discord rows)';

    public function handle(MemberLeftovers $leftovers): int
    {
        $dry = (bool) $this->option('dry-run');

        $counts = $leftovers->ofMembersWhoAreGone(! $dry);

        $this->table(
            ['Table', $dry ? 'Rows that would be deleted' : 'Rows deleted'],
            collect($counts)->map(fn (int $count, string $table) => [$table, number_format($count)])->values()->all(),
        );

        $total = array_sum($counts);

        $this->info($dry
            ? number_format($total).' row(s) belong to accounts that no longer exist. Run it again without --dry-run to delete them.'
            : number_format($total).' row(s) of accounts that no longer exist were deleted.');

        $this->line('The files of accounts deleted earlier cannot be found from here (the rows that named them went with the account). Zips of data exports are swept by the scheduler (PruneDataExports); see docs/account-deletion.md for the rest.');

        return self::SUCCESS;
    }
}
