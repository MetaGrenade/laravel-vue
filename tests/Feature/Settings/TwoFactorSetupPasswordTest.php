<?php

namespace Tests\Feature\Settings;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Security\SignInMethods;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setting two-factor up asks for the password. Turning it on looks harmless, but from a browser that was left signed in, or whose session
 * was taken, it puts the attacker's authenticator on the account: the owner meets a code prompt they cannot answer at every sign-in, and
 * the attacker holds the recovery codes the confirmation shows. Starting a setup and confirming it both ask, because the secret of a
 * setup that was started is on the Security page until it is confirmed. docs/two-factor.md, "Turning it on".
 */
class TwoFactorSetupPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, int>
     */
    private function confirmedJustNow(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /**
     * A setup that was started and not confirmed: the secret is stored and, with it, shown on the Security page.
     */
    private function startedSetup(User $member): string
    {
        $secret = TwoFactorAuthenticator::generateSecret();

        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return $secret;
    }

    private function aRealCodeFor(string $secret): string
    {
        return TwoFactorAuthenticator::code($secret, now()->timestamp);
    }

    private function madeUpAddress(): User
    {
        // What Steam, and a Discord account with no address, get: it looks like an email and receives nothing.
        return User::factory()->create(['email' => 'steam_76561198000000000@oauth.local']);
    }

    private function assertNothingWasSetUp(User $member): void
    {
        $member->refresh();

        $this->assertNull($member->two_factor_secret, 'no secret was made');
        $this->assertNull($member->two_factor_confirmed_at, 'and sign-in does not ask for a code');
        $this->assertNull($member->two_factor_recovery_codes);
    }

    private function assertTheSetupWasNotFinished(User $member): void
    {
        $member->refresh();

        $this->assertNull($member->two_factor_confirmed_at, 'the setup is not confirmed, so sign-in does not ask for a code');
        $this->assertNull($member->two_factor_recovery_codes, 'and no recovery codes were made for whoever asked');
    }

    // --- Starting a setup -----------------------------------------------------------------------------

    #[Test]
    public function starting_a_setup_asks_for_the_password_and_starts_nothing_until_it_is_given(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('security.mfa.store'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNothingWasSetUp($member);

        // And the Security page has no secret to show.
        $this->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingSecret', null)->where('twoFactorEnabled', false));
    }

    #[Test]
    public function with_the_password_given_a_moment_ago_a_setup_starts(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->post(route('security.mfa.store'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-secret-generated');

        $member->refresh();
        $this->assertNotNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_confirmed_at, 'it is not on until a code is confirmed');
    }

    #[Test]
    public function a_confirmation_older_than_the_timeout_is_asked_for_again(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
            ->post(route('security.mfa.store'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNothingWasSetUp($member);
    }

    #[Test]
    public function the_confirm_page_is_the_way_through(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->from(route('security.edit'))
            ->post(route('security.mfa.store'))
            ->assertRedirect(route('password.confirm'));

        // The password is given, and the member lands back on the page they came from and presses the button again.
        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('security.edit'));

        $this->post(route('security.mfa.store'))
            ->assertSessionHas('status', 'two-factor-secret-generated');

        $this->assertNotNull($member->fresh()->two_factor_secret);
    }

    #[Test]
    public function a_wrong_password_starts_nothing(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('password.confirm'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->post(route('security.mfa.store'))->assertRedirect(route('password.confirm'));

        $this->assertNothingWasSetUp($member);
    }

    #[Test]
    public function a_visit_from_the_settings_page_is_sent_to_the_confirm_page_as_a_get(): void
    {
        // What the page's Inertia visit sends. A `POST` answered with a 302 is followed by the browser as a `GET` of the confirm page (the
        // 303 Inertia substitutes for a 302 is only for `PUT`, `PATCH` and `DELETE`, which turning it off is, and which a 302 would repeat).
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('security.mfa.store'), [], [
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html, application/xhtml+xml',
            ])
            ->assertStatus(302)
            ->assertRedirect(route('password.confirm'));

        $this->assertNothingWasSetUp($member);
    }

    // --- Finishing a setup ----------------------------------------------------------------------------

    #[Test]
    public function a_setup_that_was_started_cannot_be_finished_without_the_password(): void
    {
        // The case the second check is for: whoever holds the session reads the secret from the Security page, as the member does, and
        // has a real code for it. Without the password that is not enough.
        $member = User::factory()->create();
        $secret = $this->startedSetup($member);

        Carbon::setTestNow(Carbon::now());

        $this->actingAs($member)
            ->post(route('security.mfa.confirm'), ['code' => $this->aRealCodeFor($secret)])
            ->assertRedirect(route('password.confirm'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);

        $this->assertTheSetupWasNotFinished($member);

        Carbon::setTestNow();
    }

    #[Test]
    public function with_the_password_given_a_moment_ago_the_same_code_finishes_it(): void
    {
        $member = User::factory()->create();
        $secret = $this->startedSetup($member);

        Carbon::setTestNow(Carbon::now());

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->post(route('security.mfa.confirm'), ['code' => $this->aRealCodeFor($secret)])
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-confirmed');

        $member->refresh();
        $this->assertNotNull($member->two_factor_confirmed_at);
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_SET_SIZE, TwoFactorAuthenticator::decryptRecoveryCodes($member->two_factor_recovery_codes));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_confirmation_older_than_the_timeout_is_asked_for_again_when_the_code_is_sent(): void
    {
        // A setup left for hours, then finished: the password is asked for again, and the page is still there afterwards.
        $member = User::factory()->create();
        $secret = $this->startedSetup($member);

        Carbon::setTestNow(Carbon::now());

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
            ->post(route('security.mfa.confirm'), ['code' => $this->aRealCodeFor($secret)])
            ->assertRedirect(route('password.confirm'));

        $this->assertTheSetupWasNotFinished($member);
        $this->assertNotNull($member->two_factor_secret, 'the secret is kept: the member gives the password and sends the code again');

        Carbon::setTestNow();
    }

    #[Test]
    public function cancelling_a_setup_that_was_never_confirmed_still_asks_for_nothing(): void
    {
        // Nothing is taken away by a cancel, and the owner who sees a setup they did not start must be able to get rid of it at once.
        $member = User::factory()->create();
        $this->startedSetup($member);

        $this->actingAs($member)
            ->delete(route('security.mfa.destroy'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-disabled');

        $this->assertNull($member->fresh()->two_factor_secret);
    }

    // --- Who is asked ---------------------------------------------------------------------------------

    #[Test]
    public function a_member_whose_address_the_site_made_up_is_not_asked(): void
    {
        // They have no password they were shown, and for them this is the only way to use two-factor at all.
        $member = $this->madeUpAddress();

        $this->actingAs($member)
            ->post(route('security.mfa.store'))
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-secret-generated');

        $secret = TwoFactorAuthenticator::decryptSecret($member->fresh()->two_factor_secret);
        $this->assertNotNull($secret);

        Carbon::setTestNow(Carbon::now());

        $this->post(route('security.mfa.confirm'), ['code' => $this->aRealCodeFor($secret)])
            ->assertSessionHas('status', 'two-factor-confirmed');

        $this->assertNotNull($member->fresh()->two_factor_confirmed_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_member_who_signed_up_with_a_provider_and_has_a_real_address_is_asked(): void
    {
        // The site cannot tell a password the member chose from one nobody was shown (SignInMethods), and a real address means "Forgot
        // your password?" reaches them, so they are asked like everybody else.
        $member = User::factory()->create();
        SocialAccount::create(['user_id' => $member->id, 'provider' => 'google', 'provider_id' => Str::uuid()->toString()]);

        $this->actingAs($member)
            ->post(route('security.mfa.store'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNothingWasSetUp($member);
    }

    #[Test]
    public function the_page_says_whether_it_will_ask(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorSetupAsksForPassword', true));

        $this->actingAs($this->madeUpAddress())
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorSetupAsksForPassword', false));
    }

    #[Test]
    public function the_rule_is_one_place_and_ignores_case(): void
    {
        $this->assertFalse(SignInMethods::canBeAskedForPassword(User::factory()->make(['email' => 'Discord_1@OAUTH.local'])));
        $this->assertTrue(SignInMethods::canBeAskedForPassword(User::factory()->make(['email' => 'member@example.com'])));
        $this->assertTrue(SignInMethods::canBeAskedForPassword(User::factory()->make(['email' => 'oauth.local@example.com'])), 'only the domain counts');
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->post(route('security.mfa.store'))->assertRedirect(route('login'));
        $this->post(route('security.mfa.confirm'), ['code' => '123456'])->assertRedirect(route('login'));
    }
}
