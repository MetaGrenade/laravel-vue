<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A page that carries a secret (the key just made, the secret and the codes of a two-factor setup) is stored by the browser in its history
 * so that Back can show it again, and the browser keeps that for the whole tab. Inertia can encrypt what it stores, with a key that lives
 * in sessionStorage, and can throw that key away when told to; this site did neither. Now such a page says its history is encrypted, and
 * ending a session says to clear the history, so Back after signing out cannot bring the secret back. docs/security.md ("What the server
 * sends and refuses"), docs/two-factor.md ("Seeing the recovery codes") and docs/api-keys.md ("Privacy").
 */
class SecretsStayOutOfBrowserHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const CODES = ['AAAA-1111-ZZZZ', 'BBBB-2222-YYYY', 'CCCC-3333-XXXX'];

    private function memberWithPendingSetup(): User
    {
        $member = User::factory()->create();
        $member->forceFill(['two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret())])->save();

        return $member;
    }

    private function memberWithTwoFactorOn(): User
    {
        $member = User::factory()->create();
        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(self::CODES),
        ])->save();

        return $member;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }

    private function encrypted(TestResponse $response): bool
    {
        return (bool) ($response->viewData('page')['encryptHistory'] ?? false);
    }

    private function cleared(TestResponse $response): bool
    {
        return (bool) ($response->viewData('page')['clearHistory'] ?? false);
    }

    // --- Pages that carry a secret are kept in encrypted history ---------------------------------------

    #[Test]
    public function the_page_that_shows_a_new_api_key_is_kept_in_encrypted_history(): void
    {
        $member = User::factory()->create();

        $response = $this->actingAs($member)->withSession(['plain_text_token' => '12|a-secret-that-is-shown-once'])
            ->get(route('settings.api-keys.index'))->assertOk();

        $this->assertTrue($this->encrypted($response), 'the key would be left in the browser history in the clear');
    }

    #[Test]
    public function the_page_that_shows_a_new_staff_token_is_kept_in_encrypted_history(): void
    {
        $response = $this->actingAs($this->admin())->withSession(['plain_text_token' => '13|a-secret-that-is-shown-once'])
            ->get(route('acp.tokens.index'))->assertOk();

        $this->assertTrue($this->encrypted($response));
    }

    #[Test]
    public function the_security_page_is_kept_in_encrypted_history_while_a_two_factor_setup_is_waiting_for_its_code(): void
    {
        $response = $this->actingAs($this->memberWithPendingSetup())->get(route('security.edit'))->assertOk();

        $this->assertTrue($this->encrypted($response), 'the secret and the QR code are on this page');
    }

    #[Test]
    public function the_security_page_is_kept_in_encrypted_history_when_it_shows_the_recovery_codes(): void
    {
        $response = $this->actingAs($this->memberWithTwoFactorOn())->withSession([TwoFactorAuthenticator::SHOW_RECOVERY_CODES => true])
            ->get(route('security.edit'))->assertOk();

        $this->assertTrue($this->encrypted($response));
        $this->assertStringContainsString(self::CODES[0], $response->getContent(), 'the codes are on this page, which is why');
    }

    // --- Pages that carry none are left as they were ------------------------------------------------------

    #[Test]
    public function the_pages_without_a_secret_are_left_as_the_site_is_set_up(): void
    {
        $member = User::factory()->create();

        $this->assertFalse($this->encrypted($this->actingAs($member)->get(route('settings.api-keys.index'))->assertOk()), 'no new key');
        $this->assertFalse($this->encrypted($this->actingAs($member)->get(route('security.edit'))->assertOk()), 'no setup going on');
        $this->assertFalse($this->encrypted($this->actingAs($this->memberWithTwoFactorOn())->get(route('security.edit'))->assertOk()), 'codes hidden');
    }

    #[Test]
    public function an_operator_who_encrypts_all_history_keeps_it_on_pages_without_a_secret(): void
    {
        config(['inertia.history.encrypt' => true]);

        $this->assertTrue($this->encrypted($this->actingAs(User::factory()->create())->get(route('settings.api-keys.index'))->assertOk()));
    }

    #[Test]
    public function one_request_that_carries_a_secret_does_not_encrypt_the_next_one_that_does_not(): void
    {
        $member = User::factory()->create();

        // The Inertia response factory is built once and kept by a worker that serves many requests, so a flag set for one must not stay.
        $first = $this->actingAs($member)->withSession(['plain_text_token' => '14|a-secret'])->get(route('settings.api-keys.index'))->assertOk();
        $this->assertTrue($this->encrypted($first));

        $this->flushSession();
        $second = $this->actingAs($member)->get(route('settings.api-keys.index'))->assertOk();
        $this->assertFalse($this->encrypted($second), 'the flag stayed on after the request that needed it');
    }

    // --- Ending a session clears the history ----------------------------------------------------------------

    #[Test]
    public function signing_out_tells_the_browser_to_clear_its_history_once(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post(route('logout'))->assertRedirect('/');
        $this->assertTrue(session()->get(SessionKey::CLEAR_HISTORY) === true, 'asked for with the redirect, and kept for the page after it');

        $this->assertTrue($this->cleared($this->get('/')->assertOk()), 'the page the member lands on carries it');
        $this->assertFalse($this->cleared($this->get('/')->assertOk()), 'and the page after that does not');
    }

    #[Test]
    public function deleting_the_account_tells_the_browser_to_clear_its_history(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

        $this->assertTrue($this->cleared($this->get('/')->assertOk()));
    }

    #[Test]
    public function an_account_that_was_not_deleted_does_not_clear_the_history(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->delete(route('profile.destroy'), ['password' => 'not-the-password']);

        $this->assertFalse($this->cleared($this->actingAs($member)->get(route('dashboard'))->assertOk()));
    }

    #[Test]
    public function being_signed_out_by_a_ban_tells_the_browser_to_clear_its_history(): void
    {
        $member = User::factory()->create(['is_banned' => true]);

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertTrue($this->cleared($this->get(route('login'))->assertOk()));
    }

    #[Test]
    public function signing_in_does_not_clear_anything(): void
    {
        $member = User::factory()->create();

        $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])->assertRedirect();

        $this->assertFalse($this->cleared($this->get(route('dashboard'))->assertOk()));
    }
}
