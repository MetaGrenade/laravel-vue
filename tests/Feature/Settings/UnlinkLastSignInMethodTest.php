<?php

namespace Tests\Feature\Settings;

use App\Models\SocialAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OAuth\OAuthProviders;
use App\Support\Security\SignInMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A member cannot unlink the provider that is their only way back into the account. What counts as a way in is what the site can check
 * (SignInMethods): another linked provider that is switched on, or a real email address they have verified, which "Forgot your
 * password?" can reach. docs/social-login.md, "Unlinking".
 */
class UnlinkLastSignInMethodTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $member, string $provider): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $member->id,
            'provider' => $provider,
            'provider_id' => Str::uuid()->toString(),
        ]);
    }

    private function unlink(User $member, string $provider): TestResponse
    {
        return $this->actingAs($member)->delete(route('settings.social.unlink', ['provider' => $provider]));
    }

    private function assertStillLinked(SocialAccount $account): void
    {
        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
    }

    // --- Refused ---------------------------------------------------------------------------------------

    #[Test]
    public function a_made_up_address_is_no_way_back_in(): void
    {
        // What Steam, and a Discord account with no address, get: it looks like an email and receives nothing.
        $member = User::factory()->create(['email' => 'steam_76561198000000000@oauth.local']);
        $account = $this->link($member, 'steam');

        $this->unlink($member, 'steam')
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('status', 'social-account-last-sign-in');

        $this->assertStillLinked($account);
    }

    #[Test]
    public function an_address_nobody_confirmed_is_no_way_back_in(): void
    {
        $member = User::factory()->unverified()->create();
        $account = $this->link($member, 'google');

        $this->unlink($member, 'google')
            ->assertSessionHas('status', 'social-account-last-sign-in');

        $this->assertStillLinked($account);
    }

    #[Test]
    public function the_other_provider_must_be_switched_on(): void
    {
        // A provider the staff turned off cannot be signed in with, so it is not a way in.
        $member = User::factory()->unverified()->create();
        $google = $this->link($member, 'google');
        $this->link($member, 'discord');

        SystemSetting::set('oauth_providers', array_merge(OAuthProviders::defaults(), ['discord' => false]));

        $this->unlink($member, 'google')
            ->assertSessionHas('status', 'social-account-last-sign-in');

        $this->assertStillLinked($google);
    }

    #[Test]
    public function a_row_for_a_provider_the_site_no_longer_has_is_not_a_way_in(): void
    {
        // What is left behind when a provider is removed from the code: nobody can sign in with it any more.
        $member = User::factory()->unverified()->create();
        $google = $this->link($member, 'google');
        $this->link($member, 'myspace');

        $this->unlink($member, 'google')
            ->assertSessionHas('status', 'social-account-last-sign-in');

        $this->assertStillLinked($google);
    }

    #[Test]
    public function asking_again_does_not_wear_the_refusal_down(): void
    {
        $member = User::factory()->unverified()->create();
        $account = $this->link($member, 'steam');

        $this->unlink($member, 'steam')->assertSessionHas('status', 'social-account-last-sign-in');
        $this->unlink($member, 'steam')->assertSessionHas('status', 'social-account-last-sign-in');

        $this->assertStillLinked($account);
    }

    // --- Allowed ---------------------------------------------------------------------------------------

    #[Test]
    public function a_verified_real_address_is_a_way_back_in(): void
    {
        $member = User::factory()->create();
        $account = $this->link($member, 'google');

        $this->unlink($member, 'google')
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('status', 'social-account-unlinked');

        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    #[Test]
    public function another_provider_that_is_switched_on_is_a_way_back_in(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'discord_1@oauth.local']);
        $steam = $this->link($member, 'steam');
        $this->link($member, 'discord');

        $this->unlink($member, 'steam')->assertSessionHas('status', 'social-account-unlinked');

        $this->assertDatabaseMissing('social_accounts', ['id' => $steam->id]);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $member->id, 'provider' => 'discord']);
    }

    #[Test]
    public function the_last_provider_goes_once_another_way_in_exists(): void
    {
        // The refusal is not permanent: verify the address, then it works.
        $member = User::factory()->unverified()->create();
        $account = $this->link($member, 'google');

        $this->unlink($member, 'google')->assertSessionHas('status', 'social-account-last-sign-in');
        $this->assertStillLinked($account);

        $member->forceFill(['email_verified_at' => now()])->save();

        $this->unlink($member, 'google')->assertSessionHas('status', 'social-account-unlinked');
        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    #[Test]
    public function a_provider_that_is_not_linked_changes_nothing(): void
    {
        $member = User::factory()->unverified()->create();
        $account = $this->link($member, 'google');

        $this->unlink($member, 'discord')->assertSessionHas('status', 'social-account-missing');

        $this->assertStillLinked($account);
    }

    // --- The rule itself -------------------------------------------------------------------------------

    #[Test]
    public function the_made_up_domain_is_recognised_whatever_its_case(): void
    {
        $this->assertTrue(SignInMethods::isPlaceholderEmail('steam_1@oauth.local'));
        $this->assertTrue(SignInMethods::isPlaceholderEmail('Discord_1@OAuth.Local'));
        $this->assertFalse(SignInMethods::isPlaceholderEmail('someone@example.com'));
        $this->assertFalse(SignInMethods::isPlaceholderEmail('someone@oauth.local.example.com'));
        $this->assertFalse(SignInMethods::isPlaceholderEmail(null));
    }
}
