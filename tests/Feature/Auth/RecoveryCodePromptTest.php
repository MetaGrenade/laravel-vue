<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecoveryCodePromptTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_short_set_asks_for_new_codes_and_a_full_set_does_not(): void
    {
        $short = $this->memberWithCodes(['AAAA-BBBB-CCCC']);

        $this->actingAs($short)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', true));

        $this->actingAs($short)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.replaceRecoveryCodes', true)
                ->where('recoveryCodes', ['AAAA-BBBB-CCCC'])
            );

        $full = $this->memberWithCodes(array_fill(0, TwoFactorAuthenticator::RECOVERY_SET_SIZE, 'DDDD-EEEE-FFFF'));

        $this->actingAs($full)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', false));
    }

    #[Test]
    public function signing_in_with_a_recovery_code_asks_for_a_new_set(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes([
                'ABCD-EFGH-IJKL',
                'WXYZ-MNOP-QRST',
            ]),
        ])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login'), [
            'recovery_code' => 'ABCD-EFGH-IJKL',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', true));
    }

    #[Test]
    public function generating_a_new_set_clears_the_prompt(): void
    {
        $user = $this->memberWithCodes(['AAAA-BBBB-CCCC']);

        $this->actingAs($user)
            ->post(route('security.recovery-codes.store'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', false));
    }

    #[Test]
    public function the_prompt_is_hidden_while_viewing_as_the_member(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));

        $customer = $this->memberWithCodes(['AAAA-BBBB-CCCC']);

        $this->actingAs($admin)->withSession([
            Impersonation::SESSION_KEY => [
                'admin_id' => $admin->id,
                'user_id' => $customer->id,
                'reason' => 'Checking the prompt stays hidden',
                'started_at' => now()->getTimestamp(),
                'expires_at' => now()->addMinutes(30)->getTimestamp(),
                'views' => 0,
            ],
        ])->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', false));
    }

    #[Test]
    public function a_member_without_two_factor_is_not_asked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.replaceRecoveryCodes', false));
    }

    /**
     * @param  list<string>  $codes
     */
    private function memberWithCodes(array $codes): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes($codes),
        ])->save();

        return $user;
    }
}
