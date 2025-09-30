<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_users_are_blocked_when_verification_is_required(): void
    {
        SystemSetting::set('email_verification_required', true);

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_users_can_access_when_verification_is_not_required(): void
    {
        SystemSetting::set('email_verification_required', false);

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }
}
