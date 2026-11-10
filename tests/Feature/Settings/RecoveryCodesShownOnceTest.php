<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The recovery codes are a way into the account that outlasts a session, so the Security page does not carry them on every load: they
 * come with the page for one request after a set was made, or after the password was asked for them. Any other load says how many are
 * left. docs/two-factor.md, "Seeing the codes".
 */
class RecoveryCodesShownOnceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const CODES = ['AAAA-1111-ZZZZ', 'BBBB-2222-YYYY', 'CCCC-3333-XXXX'];

    /**
     * @param  list<string>  $codes
     */
    private function memberWithCodes(array $codes = self::CODES): User
    {
        $member = User::factory()->create();

        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes($codes),
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

    private function assertTheCodesAreHidden(): void
    {
        $response = $this->get(route('security.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', []));

        foreach (self::CODES as $code) {
            $this->assertStringNotContainsString($code, $response->getContent());
        }
    }

    // --- An ordinary load -----------------------------------------------------------------------------

    #[Test]
    public function an_ordinary_load_of_the_page_says_how_many_are_left_and_carries_none_of_them(): void
    {
        $member = $this->memberWithCodes();

        $response = $this->actingAs($member)->get(route('security.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('recoveryCodes', [])
            ->where('recoveryCodesLeft', count(self::CODES))
            ->where('twoFactorConfirmed', true)
        );

        foreach (self::CODES as $code) {
            $this->assertStringNotContainsString($code, $response->getContent(), 'not even in the page source');
        }
    }

    #[Test]
    public function no_other_page_carries_them_either(): void
    {
        $member = $this->memberWithCodes();

        foreach (['dashboard', 'profile.edit', 'password.edit'] as $name) {
            $content = $this->actingAs($member)->get(route($name))->assertOk()->getContent();

            foreach (self::CODES as $code) {
                $this->assertStringNotContainsString($code, $content, $name);
            }
        }
    }

    #[Test]
    public function a_request_cannot_ask_for_them_by_itself(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->get(route('security.edit', [TwoFactorAuthenticator::SHOW_RECOVERY_CODES => 1, 'recoveryCodes' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', []));

        $this->post(route('security.mfa.store'), [TwoFactorAuthenticator::SHOW_RECOVERY_CODES => true]);
        $this->assertTheCodesAreHidden();

        $this->get(route('security.edit'), ['X-Inertia-Partial-Component' => 'settings/Security', 'X-Inertia-Partial-Data' => 'recoveryCodes'])
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', []));
    }

    #[Test]
    public function a_member_without_two_factor_has_none_and_is_told_none(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', [])->where('recoveryCodesLeft', 0));
    }

    #[Test]
    public function codes_that_cannot_be_read_are_none_and_do_not_break_the_page(): void
    {
        // A key that was changed since they were stored: counted as none left (so the bar asks for a new set), never an error page.
        $member = $this->memberWithCodes();
        $member->forceFill(['two_factor_recovery_codes' => 'not something this key encrypted'])->save();

        $this->actingAs($member)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', [])->where('recoveryCodesLeft', 0)->where('auth.replaceRecoveryCodes', true));

        $this->withSession([TwoFactorAuthenticator::SHOW_RECOVERY_CODES => true])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', []));
    }

    #[Test]
    public function the_reader_gives_back_the_strings_that_are_stored_and_nothing_else(): void
    {
        $this->assertSame([], TwoFactorAuthenticator::readRecoveryCodes(null));
        $this->assertSame([], TwoFactorAuthenticator::readRecoveryCodes(''));
        $this->assertSame([], TwoFactorAuthenticator::readRecoveryCodes('garbage'));
        $this->assertSame(self::CODES, TwoFactorAuthenticator::readRecoveryCodes(TwoFactorAuthenticator::encryptRecoveryCodes(self::CODES)));
        $this->assertSame(['AAAA-1111-ZZZZ'], TwoFactorAuthenticator::readRecoveryCodes(TwoFactorAuthenticator::encryptRecoveryCodes([1, ['x'], 'AAAA-1111-ZZZZ', null])));
    }

    // --- A set that was just made ---------------------------------------------------------------------

    #[Test]
    public function turning_two_factor_on_shows_the_first_set_once(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post(route('security.mfa.store'))->assertRedirect();

        $secret = TwoFactorAuthenticator::decryptSecret($member->fresh()->two_factor_secret);
        $this->assertNotNull($secret);

        Carbon::setTestNow(Carbon::now());

        $this->post(route('security.mfa.confirm'), ['code' => TwoFactorAuthenticator::code($secret, now()->timestamp)])
            ->assertRedirect()
            ->assertSessionHas('status', 'two-factor-confirmed')
            ->assertSessionHas(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);

        $stored = TwoFactorAuthenticator::decryptRecoveryCodes($member->fresh()->two_factor_recovery_codes);
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_SET_SIZE, $stored);

        $this->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', $stored)->where('recoveryCodesLeft', TwoFactorAuthenticator::RECOVERY_SET_SIZE));

        $this->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', [])->where('recoveryCodesLeft', TwoFactorAuthenticator::RECOVERY_SET_SIZE));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_wrong_code_shows_nothing(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->post(route('security.mfa.store'));

        $this->post(route('security.mfa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);

        $this->assertNull($member->fresh()->two_factor_recovery_codes);
    }

    #[Test]
    public function a_new_set_is_shown_once_and_the_old_one_is_not_shown_at_all(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->post(route('security.recovery-codes.store'))
            ->assertSessionHas(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);

        $new = TwoFactorAuthenticator::decryptRecoveryCodes($member->fresh()->two_factor_recovery_codes);
        $this->assertNotEquals(self::CODES, $new);

        $this->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', $new));

        $this->assertTheCodesAreHidden();
    }

    #[Test]
    public function a_new_set_that_was_refused_for_want_of_the_password_shows_nothing(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);

        $this->assertTheCodesAreHidden();
    }

    // --- Asking to see them ---------------------------------------------------------------------------

    #[Test]
    public function asking_to_see_them_asks_for_the_password_first_and_shows_nothing_meanwhile(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);

        $this->assertTheCodesAreHidden();
    }

    #[Test]
    public function the_confirm_page_is_the_way_through_and_the_codes_come_once(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)->get(route('security.recovery-codes.show'))->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('security.recovery-codes.show'));

        $this->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);

        $this->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', self::CODES)->where('recoveryCodesLeft', count(self::CODES)));

        $this->assertTheCodesAreHidden();
    }

    #[Test]
    public function a_wrong_password_shows_nothing(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)->get(route('security.recovery-codes.show'));

        $this->post(route('password.confirm'), ['password' => 'wrong-password'])->assertSessionHasErrors('password');

        $this->get(route('security.recovery-codes.show'))->assertRedirect(route('password.confirm'));

        $this->assertTheCodesAreHidden();
    }

    #[Test]
    public function with_the_password_confirmed_a_moment_ago_it_goes_straight_back_with_the_codes(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);

        $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', self::CODES));
    }

    #[Test]
    public function a_confirmation_older_than_the_timeout_is_asked_for_again(): void
    {
        $member = $this->memberWithCodes();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
            ->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);
    }

    #[Test]
    public function the_next_load_is_the_only_one_that_carries_them_even_if_it_is_not_this_page(): void
    {
        // A flash is for the request after it, and no other: a page opened in between uses it up, so a thief who opens this page later has
        // nothing, however soon after the member looked.
        $member = $this->memberWithCodes();

        $this->actingAs($member)->withSession($this->confirmedJustNow())->get(route('security.recovery-codes.show'));

        $this->get(route('dashboard'))->assertOk();

        $this->assertTheCodesAreHidden();
    }

    #[Test]
    public function seeing_them_changes_nothing_about_the_set(): void
    {
        $member = $this->memberWithCodes();
        $before = $member->two_factor_recovery_codes;

        $this->actingAs($member)->withSession($this->confirmedJustNow())->get(route('security.recovery-codes.show'));
        $this->get(route('security.edit'))->assertOk();
        $this->get(route('security.edit'))->assertOk();

        $this->assertSame($before, $member->fresh()->two_factor_recovery_codes);
        $this->assertSame(self::CODES, TwoFactorAuthenticator::readRecoveryCodes($member->fresh()->two_factor_recovery_codes));
    }

    #[Test]
    public function a_member_without_a_confirmed_setup_has_nothing_to_be_shown(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->withSession($this->confirmedJustNow())
            ->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);

        $this->post(route('security.mfa.store'));

        $this->get(route('security.recovery-codes.show'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionMissing(TwoFactorAuthenticator::SHOW_RECOVERY_CODES);
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('security.recovery-codes.show'))->assertRedirect(route('login'));
    }
}
