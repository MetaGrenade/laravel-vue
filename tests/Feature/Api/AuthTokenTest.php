<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Getting a key by signing in over the API, and giving it back (POST and DELETE /api/v1/auth/token).
 */
class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $extra = []): TestResponse
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        return $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone', ...$extra]);
    }

    public function test_signing_in_gives_a_key_with_every_scope_unless_it_asks_for_fewer(): void
    {
        $this->signIn()->assertCreated()->assertJsonStructure(['token', 'expires_at', 'user']);

        $this->assertSame(['*'], PersonalAccessToken::query()->sole()->abilities);
    }

    public function test_a_key_can_ask_for_some_scopes(): void
    {
        $this->signIn(['abilities' => ['profile:read', 'polls:vote']])->assertCreated();

        $this->assertSame(['profile:read', 'polls:vote'], PersonalAccessToken::query()->sole()->abilities);
    }

    public function test_a_scope_the_site_does_not_have_is_refused(): void
    {
        $this->signIn(['abilities' => ['profile:read', 'delete-everything']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities.1');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_key_with_no_scopes_at_all_is_refused(): void
    {
        $this->signIn(['abilities' => []])->assertUnprocessable()->assertJsonValidationErrors('abilities');
    }

    public function test_a_wrong_password_gives_no_key(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'not-it'])->assertUnprocessable();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_giving_a_key_back_revokes_it_and_it_stops_working(): void
    {
        $key = User::factory()->create()->createToken('phone');
        $headers = ['Authorization' => 'Bearer '.$key->plainTextToken];

        $this->withHeaders($headers)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertNotNull($key->accessToken->fresh()->revoked_at);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_the_request_that_gives_a_key_back_is_in_its_log(): void
    {
        $key = User::factory()->create()->createToken('phone');

        $this->withToken($key->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertDatabaseHas('token_logs', [
            'personal_access_token_id' => $key->accessToken->id,
            'method' => 'DELETE',
            'http_status' => 204,
            'status' => 'success',
        ]);
        $this->assertSame(1, TokenLog::query()->count());
    }

    public function test_giving_back_a_key_leaves_the_others_alone(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('phone');
        $second = $user->createToken('laptop');

        $this->withToken($first->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertNull($second->accessToken->fresh()->revoked_at);
    }
}
