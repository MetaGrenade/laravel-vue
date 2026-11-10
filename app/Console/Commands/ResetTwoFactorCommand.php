<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Routing\ZiggyRouteGroup;
use App\Support\Security\TwoFactorReset;
use Illuminate\Console\Command;

/**
 * Switch two-factor off for an account from the console (docs/two-factor.md, "When a member is locked out").
 *
 * The admin area can do this for every account but one's own, and for staff only as an administrator. When the one administrator is the
 * person who lost their authenticator app, nobody is left to ask, so whoever runs the site does it here, on the server. Having a shell
 * on the server is the authority: nothing is checked about who is asking beyond that, the account's own password is still needed to sign
 * in afterwards, and the reset is written to the audit log (with no signed-in person) and emailed to the member like any other.
 */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'metaforger:two-factor:reset
        {user : The account, by id or by email address}
        {--reason= : Why, in a few words (a ticket number will do). Kept in the audit log; asked for when it is not given}
        {--force : Do not ask for confirmation}';

    protected $description = 'Switch two-factor off for an account that lost its authenticator app and recovery codes (docs/two-factor.md)';

    public function handle(TwoFactorReset $reset): int
    {
        $member = $this->find((string) $this->argument('user'));

        if ($member === null) {
            $this->error('No account has that id or email address. Nothing was changed.');

            return self::FAILURE;
        }

        if (! TwoFactorReset::isSetUp($member)) {
            $this->error(sprintf('Two-factor is not set up for %s (%s). Nothing was changed.', $member->nickname, $member->email));

            return self::FAILURE;
        }

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        $this->line(sprintf('Account: %s (%s), id %d.', $member->nickname, $member->email, $member->id));

        if ($member->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES)) {
            $this->warn('This is a member of staff. Be sure it is them: with two-factor off, their password is all that stands between a stranger and the admin area.');
        }

        if (! $this->option('force') && ! $this->confirm('Turn two-factor off for this account?', false)) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $told = $reset->reset($member, null, $reason);

        $this->info('Two-factor is off for this account. They sign in with their password alone, and can set it up again in Settings → Security.');
        $this->line($told
            ? 'The member was emailed.'
            : 'The member was not emailed: their address cannot receive mail.');

        return self::SUCCESS;
    }

    /**
     * By id when it is a number, otherwise by email address (either case).
     */
    private function find(string $given): ?User
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        if (ctype_digit($given)) {
            return User::query()->find((int) $given);
        }

        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($given)])->first();
    }

    /**
     * Why it is being done: from `--reason`, or asked for. It is the audit log's record of it, so a few words are required.
     */
    private function reason(): ?string
    {
        $reason = $this->option('reason');

        if (! is_string($reason) || trim($reason) === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Say why with --reason="...": it is kept in the audit log. Nothing was changed.');

                return null;
            }

            $reason = (string) $this->ask('Why are you turning two-factor off for this account? (a ticket number will do; kept in the audit log)');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            $this->error('Give a reason of at least 5 characters: it is kept in the audit log. Nothing was changed.');

            return null;
        }

        return mb_substr($reason, 0, 500);
    }
}
