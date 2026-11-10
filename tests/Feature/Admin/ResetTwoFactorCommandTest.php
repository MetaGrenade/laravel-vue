<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TwoFactorWasReset;
use App\Support\Security\SignInMethods;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `php artisan metaforger:two-factor:reset`: switching two-factor off from the console, for the case the admin area cannot cover (the
 * one administrator is the person who lost their authenticator app). docs/two-factor.md, "When a member is locked out".
 */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lockedOut(array $attributes = []): User
    {
        $member = User::factory()->create($attributes);

        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['AAAA-1111-ZZZZ']),
        ])->save();

        return $member;
    }

    private function assertStillOn(User $member): void
    {
        $this->assertNotNull($member->fresh()->two_factor_confirmed_at, 'still asked for at sign-in');
        $this->assertNotNull($member->fresh()->two_factor_secret);
    }

    private function assertSwitchedOff(User $member): void
    {
        $member->refresh();

        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_confirmed_at);
        $this->assertNull($member->two_factor_recovery_codes);
    }

    #[Test]
    public function it_switches_it_off_by_id_tells_the_member_and_writes_the_audit_log(): void
    {
        Notification::fake();
        $member = $this->lockedOut();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--reason' => 'Ticket 4411: lost the phone', '--force' => true])
            ->expectsOutputToContain('Two-factor is off for this account')
            ->expectsOutputToContain('The member was emailed.')
            ->assertSuccessful();

        $this->assertSwitchedOff($member);
        Notification::assertSentTo($member, TwoFactorWasReset::class);

        $entry = AuditLog::query()->sole();
        $this->assertSame('users.two-factor.reset', $entry->event);
        $this->assertSame(AuditLog::SUCCEEDED, $entry->outcome);
        $this->assertNull($entry->actor_id, 'nobody was signed in: it was done on the server');
        $this->assertSame($member->id, (int) $entry->subject_id);
        $this->assertSame('Ticket 4411: lost the phone', $entry->meta['reason']);
        $this->assertSame('confirmed', $entry->meta['was']);
        $this->assertSame('console', $entry->meta['from']);
    }

    #[Test]
    public function it_finds_the_account_by_email_address_in_either_case(): void
    {
        $member = $this->lockedOut(['email' => 'locked@example.com']);

        $this->artisan('metaforger:two-factor:reset', ['user' => ' Locked@Example.COM ', '--reason' => 'Ticket 4411', '--force' => true])->assertSuccessful();

        $this->assertSwitchedOff($member);
    }

    #[Test]
    public function an_account_that_does_not_exist_is_refused_and_nothing_changes(): void
    {
        $member = $this->lockedOut();

        $this->artisan('metaforger:two-factor:reset', ['user' => 'nobody@example.com', '--reason' => 'Ticket 4411', '--force' => true])
            ->expectsOutputToContain('No account has that id or email address')
            ->assertFailed();

        $this->artisan('metaforger:two-factor:reset', ['user' => '99999', '--reason' => 'Ticket 4411', '--force' => true])->assertFailed();

        $this->assertStillOn($member);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function an_account_with_nothing_set_up_is_refused(): void
    {
        Notification::fake();
        $member = User::factory()->create();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--reason' => 'Ticket 4411', '--force' => true])
            ->expectsOutputToContain('Two-factor is not set up')
            ->assertFailed();

        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function a_reason_is_required_when_nobody_can_be_asked(): void
    {
        $member = $this->lockedOut();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Say why with --reason')
            ->assertFailed();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--reason' => 'abc', '--force' => true])
            ->expectsOutputToContain('at least 5 characters')
            ->assertFailed();

        $this->assertStillOn($member);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function it_asks_for_the_reason_and_for_confirmation_when_it_is_run_by_hand(): void
    {
        $member = $this->lockedOut();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id])
            ->expectsQuestion('Why are you turning two-factor off for this account? (a ticket number will do; kept in the audit log)', 'Ticket 4411: lost the phone')
            ->expectsConfirmation('Turn two-factor off for this account?', 'yes')
            ->assertSuccessful();

        $this->assertSwitchedOff($member);
        $this->assertSame('Ticket 4411: lost the phone', AuditLog::query()->sole()->meta['reason']);
    }

    #[Test]
    public function declining_the_confirmation_changes_nothing(): void
    {
        Notification::fake();
        $member = $this->lockedOut();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--reason' => 'Ticket 4411'])
            ->expectsConfirmation('Turn two-factor off for this account?', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertStillOn($member);
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function an_administrator_can_be_reset_here_and_is_warned_about(): void
    {
        // Whoever has a shell on the server runs the site: this is the way back when the one administrator lost their phone.
        Role::findOrCreate('admin');
        $administrator = $this->lockedOut();
        $administrator->assignRole('admin');

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $administrator->id, '--reason' => 'Ticket 4411', '--force' => true])
            ->expectsOutputToContain('This is a member of staff')
            ->assertSuccessful();

        $this->assertSwitchedOff($administrator);
    }

    #[Test]
    public function a_member_whose_address_the_site_made_up_is_not_emailed(): void
    {
        Notification::fake();
        $member = $this->lockedOut(['email' => 'steam_1@'.SignInMethods::PLACEHOLDER_DOMAIN]);

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $member->id, '--reason' => 'Ticket 4411', '--force' => true])
            ->expectsOutputToContain('The member was not emailed')
            ->assertSuccessful();

        $this->assertSwitchedOff($member);
        Notification::assertNothingSent();
    }
}
