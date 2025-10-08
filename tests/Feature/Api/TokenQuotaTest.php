<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TokenQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);

        $this->actingAs($admin);

        return $admin;
    }

    private function createTokenLog(PersonalAccessToken $token, array $overrides = []): TokenLog
    {
        $log = new TokenLog([
            'personal_access_token_id' => $token->id,
            'token_name' => $token->name,
            'route' => $overrides['route'] ?? '/api/example',
            'method' => $overrides['method'] ?? 'GET',
            'status' => $overrides['status'] ?? 'success',
            'http_status' => $overrides['http_status'] ?? 200,
        ]);

        $createdAt = $overrides['created_at'] ?? Carbon::now();
        $updatedAt = $overrides['updated_at'] ?? $createdAt;

        $log->created_at = $createdAt;
        $log->updated_at = $updatedAt;

        $log->save();

        return $log;
    }

    public function test_token_requests_are_throttled_when_daily_quota_exceeded(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('Integration Test');

        $accessToken = $newToken->accessToken;
        $accessToken->forceFill([
            'hourly_quota' => 10,
            'daily_quota' => 2,
        ])->save();

        $plainText = $newToken->plainTextToken;

        $headers = [
            'Authorization' => 'Bearer '.$plainText,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)->getJson('/api/user')->assertOk();
        $this->withHeaders($headers)->getJson('/api/user')->assertOk();

        $response = $this->withHeaders($headers)->getJson('/api/user');

        $response->assertStatus(429)
            ->assertJson([
                'message' => 'API quota exceeded for this token.',
                'limit' => 2,
                'period' => 'day',
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame(2, TokenLog::where('personal_access_token_id', $accessToken->id)->count());
    }

    public function test_token_dashboard_includes_quota_metrics(): void
    {
        $this->signInAdmin();

        $tokenOwner = User::factory()->create();
        $newToken = $tokenOwner->createToken('Metrics Token');
        $token = $newToken->accessToken;
        $token->forceFill([
            'hourly_quota' => 5,
            'daily_quota' => 20,
        ])->save();

        $now = Carbon::now();

        $this->createTokenLog($token, ['created_at' => $now->copy()->subMinutes(10)]);
        $this->createTokenLog($token, ['created_at' => $now->copy()->subMinutes(45)]);
        $this->createTokenLog($token, ['created_at' => $now->copy()->subHours(5)]);
        $this->createTokenLog($token, ['created_at' => $now->copy()->subDays(2)]);

        $response = $this->get(route('acp.tokens.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->where('tokens.data.0.hourly_quota', 5)
            ->where('tokens.data.0.daily_quota', 20)
            ->where('tokens.data.0.hourly_usage', 2)
            ->where('tokens.data.0.daily_usage', 3)
        );
    }
}
