<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What makes a key stop working: being revoked, running out, or its owner going (see docs/api-keys.md).
 */
class ApiKeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: PersonalAccessToken, 1: array<string, string>}
     */
    private function key(User $user, array $abilities = ['*']): array
    {
        $key = $user->createToken('CI', $abilities);

        return [$key->accessToken, ['Authorization' => 'Bearer '.$key->plainTextToken, 'Accept' => 'application/json']];
    }

    /** The guard keeps the user it found for the rest of the test, as a second request in one process would not in life. */
    private function asANewRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_a_key_that_is_in_date_and_not_revoked_works(): void
    {
        [, $headers] = $this->key(User::factory()->create());

        $this->withHeaders($headers)->getJson('/api/user')->assertOk();
    }

    public function test_a_revoked_key_is_refused(): void
    {
        [$key, $headers] = $this->key(User::factory()->create());

        $this->withHeaders($headers)->getJson('/api/user')->assertOk();

        $key->forceFill(['revoked_at' => now()])->save();
        $this->asANewRequest();

        $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_revoked_key_is_refused_on_every_route_that_takes_one(): void
    {
        [$key, $headers] = $this->key(User::factory()->create());
        $key->forceFill(['revoked_at' => now()])->save();

        $this->withHeaders($headers)->getJson('/api/v1/profile')->assertUnauthorized();
        $this->asANewRequest();
        $this->withHeaders($headers)->deleteJson('/api/v1/auth/token')->assertUnauthorized();

        $this->assertModelExists($key);
    }

    public function test_a_revoked_key_that_is_restored_works_again(): void
    {
        [$key, $headers] = $this->key(User::factory()->create());
        $key->forceFill(['revoked_at' => now()])->save();

        $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();

        $key->forceFill(['revoked_at' => null])->save();
        $this->asANewRequest();

        $this->withHeaders($headers)->getJson('/api/user')->assertOk();
    }

    public function test_a_revoked_key_is_not_marked_as_used(): void
    {
        [$key, $headers] = $this->key(User::factory()->create());
        $key->forceFill(['revoked_at' => now()])->save();

        $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();

        $this->assertNull($key->fresh()->last_used_at, 'a refused key has not been used');
    }

    public function test_a_key_that_has_expired_is_refused(): void
    {
        [$key, $headers] = $this->key(User::factory()->create());
        $key->forceFill(['expires_at' => now()->addDay()])->save();

        $this->withHeaders($headers)->getJson('/api/user')->assertOk();

        $this->travel(2)->days();
        $this->asANewRequest();

        $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_revoking_one_key_leaves_the_owners_others_working(): void
    {
        $user = User::factory()->create();
        [$revoked] = $this->key($user);
        [, $other] = $this->key($user);
        $revoked->forceFill(['revoked_at' => now()])->save();

        $this->withHeaders($other)->getJson('/api/user')->assertOk();
    }
}
