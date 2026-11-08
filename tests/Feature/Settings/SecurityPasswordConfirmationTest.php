<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A browser that was left signed in must not be enough to take a protection off an account: switching two-factor off, and making
 * recovery codes that replace the ones written down, ask for the password again (Laravel's `password.confirm`, good for
 * AUTH_PASSWORD_TIMEOUT seconds). docs/two-factor.md, "Turning it off".
 */
class SecurityPasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithTwoFactor(): User
    {
        $member = User::factory()->create();

        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(array_fill(0, TwoFactorAuthenticator::RECOVERY_SET_SIZE, 'AAAA-BBBB-CCCC')),
        ])->save();

        return $member;
    }

    /**
     * @return array<string, int>
     */
    private function confirmedJustNow(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    private function assertTwoFactorIsStillOn(User $member): void
    {
        $member->refresh();

        $this->assertNotNull($member->two_factor_secret, 'the secret is still there');
        $this->assertNotNull($member->two_factor_confirmed_at, 'and still asked for at sign-in');
    }

    // --- Switching two-factor off ---------------------------------------------------------------------

    #[Test]
    public function switching_two_factor_off_asks_for_the_password_and_changes_nothing_until_it_is_given(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->delete(route('security.mfa.destroy'))
            ->assertRedirect(route('password.confirm'));

        $this->assertTwoFactorIsStillOn($member);
    }

    #[Test]
    public function with_the_password_confirmed_a_moment_ago_it_switches_off(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->delete(route('security.mfa.destroy'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-disabled');

        $member->refresh();
        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_confirmed_at);
        $this->assertNull($member->two_factor_recovery_codes);
    }

    #[Test]
    public function a_confirmation_older_than_the_timeout_is_asked_for_again(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
            ->delete(route('security.mfa.destroy'))
            ->assertRedirect(route('password.confirm'));

        $this->assertTwoFactorIsStillOn($member);
    }

    #[Test]
    public function the_confirm_page_is_the_way_through(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)->from(route('security.edit'))
            ->delete(route('security.mfa.destroy'))
            ->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('security.edit'));

        $this->delete(route('security.mfa.destroy'))
            ->assertSessionHas('status', 'two-factor-disabled');

        $this->assertNull($member->fresh()->two_factor_confirmed_at);
    }

    #[Test]
    public function a_wrong_password_unlocks_nothing(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->post(route('password.confirm'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->delete(route('security.mfa.destroy'))
            ->assertRedirect(route('password.confirm'));

        $this->assertTwoFactorIsStillOn($member);
    }

    #[Test]
    public function a_visit_from_the_settings_page_is_sent_to_the_confirm_page_with_a_303(): void
    {
        // What the page's Inertia visit sends. A `DELETE` that is answered with a plain 302 is repeated by the browser as a `DELETE` of
        // the confirm page; Inertia's middleware turns it into the 303 that makes it a `GET`.
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->delete(route('security.mfa.destroy'), [], [
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html, application/xhtml+xml',
            ])
            ->assertStatus(303)
            ->assertRedirect(route('password.confirm'));

        $this->assertTwoFactorIsStillOn($member);
    }

    #[Test]
    public function cancelling_a_setup_that_was_never_confirmed_asks_for_nothing(): void
    {
        // Two-factor was never asked for at sign-in, so there is nothing to take away, and "Cancel" stays one click.
        $member = User::factory()->create();
        $this->actingAs($member)->post(route('security.mfa.store'))->assertRedirect();
        $this->assertNotNull($member->fresh()->two_factor_secret);

        $this->delete(route('security.mfa.destroy'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-disabled');

        $this->assertNull($member->fresh()->two_factor_secret);
    }

    #[Test]
    public function setting_it_up_again_on_top_of_a_confirmed_secret_does_not_switch_it_off_through_the_back_door(): void
    {
        // A new secret clears the confirmation, which is what sign-in goes by, so this used to be a way to turn it off without a password.
        $member = $this->memberWithTwoFactor();
        $secret = $member->two_factor_secret;

        $this->actingAs($member)
            ->post(route('security.mfa.store'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-already-on');

        $this->assertTwoFactorIsStillOn($member);
        $this->assertSame($secret, $member->two_factor_secret, 'and the secret in the authenticator app still works');

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->post(route('security.mfa.store'))
            ->assertSessionHas('status', 'two-factor-already-on');

        $this->assertSame($secret, $member->fresh()->two_factor_secret, 'even right after the password was given: turn it off first');
    }

    // --- Recovery codes -------------------------------------------------------------------------------

    #[Test]
    public function a_new_set_of_recovery_codes_asks_for_the_password_and_keeps_the_old_set_until_it_is_given(): void
    {
        $member = $this->memberWithTwoFactor();
        $before = $member->two_factor_recovery_codes;

        $this->actingAs($member)
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($before, $member->fresh()->two_factor_recovery_codes, 'the codes written down still work');
    }

    #[Test]
    public function with_the_password_confirmed_a_moment_ago_a_new_set_replaces_the_old_one(): void
    {
        $member = $this->memberWithTwoFactor();
        $before = TwoFactorAuthenticator::decryptRecoveryCodes($member->two_factor_recovery_codes);

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect()
            ->assertSessionHas('status', 'recovery-codes-generated');

        $after = TwoFactorAuthenticator::decryptRecoveryCodes($member->fresh()->two_factor_recovery_codes);
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_SET_SIZE, $after);
        $this->assertNotEquals($before, $after);
    }

    // --- What stays open, on purpose -------------------------------------------------------------------

    #[Test]
    public function ending_another_browsers_session_does_not_ask_for_the_password(): void
    {
        // It only signs a device out: whoever does it gains nothing, and the owner who lost a phone needs it done at once. A password
        // prompt would make that slower exactly when speed matters. docs/two-factor.md says so.
        $member = $this->memberWithTwoFactor();
        $sessionId = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $member->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Some other browser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($member)
            ->delete(route('security.sessions.destroy', $sessionId))
            ->assertRedirect()
            ->assertSessionHas('status', 'session-revoked');

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }

    #[Test]
    public function switching_two_factor_on_does_not_ask_for_the_password(): void
    {
        // Turning a protection on takes nothing away, and a member who has only ever signed in with a provider has no password to give.
        $this->actingAs(User::factory()->create())
            ->post(route('security.mfa.store'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-secret-generated');
    }
}
