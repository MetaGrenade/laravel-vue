<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_revoke_an_active_session(): void
    {
        $user = User::factory()->create();
        $sessionId = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Laravel Test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->actingAs($user)->delete(route('security.sessions.destroy', $sessionId));

        $response->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }

    /** @test */
    public function user_can_enable_confirm_and_refresh_multi_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('security.mfa.store'))
            ->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $secret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);
        $this->assertNotNull($secret);

        $frozenNow = Carbon::now();
        Carbon::setTestNow($frozenNow);

        $code = TwoFactorAuthenticator::code($secret, $frozenNow->timestamp);

        $this->actingAs($user)
            ->post(route('security.mfa.confirm'), ['code' => $code])
            ->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_recovery_codes);

        $initialRecoveryCodes = TwoFactorAuthenticator::decryptRecoveryCodes($user->two_factor_recovery_codes);
        $this->assertCount(8, $initialRecoveryCodes);

        $this->actingAs($user)
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect();

        $user->refresh();

        $rotatedCodes = TwoFactorAuthenticator::decryptRecoveryCodes($user->two_factor_recovery_codes);
        $this->assertCount(8, $rotatedCodes);
        $this->assertNotEquals($initialRecoveryCodes, $rotatedCodes);

        Carbon::setTestNow();
    }

    /** @test */
    public function pending_secret_and_qr_code_are_shown_after_generation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('security.mfa.store'))
            ->assertRedirect();

        $user->refresh();

        $secret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->where('pendingSecret', $secret)
                ->where('qrCodeUrl', TwoFactorAuthenticator::makeQrCodeUrl($user, $secret))
            );
    }
}
