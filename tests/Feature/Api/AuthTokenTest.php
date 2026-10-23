<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Models\TokenLog;
use App\Models\User;
use App\Support\Api\ApiKeys;
use Carbon\CarbonImmutable;
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

    /** Sign in as somebody who already exists (a second time, or as the owner of other keys). */
    private function signInAs(User $user, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone', ...$extra]);
    }

    public function test_signing_in_gives_a_key_that_can_only_read_the_profile_unless_it_asks_for_more(): void
    {
        $this->signIn()
            ->assertCreated()
            ->assertJsonStructure(['token', 'abilities', 'expires_at', 'user'])
            ->assertJson(['abilities' => ['profile:read']]);

        $this->assertSame(['profile:read'], PersonalAccessToken::query()->sole()->abilities);
    }

    public function test_the_site_chooses_what_a_key_made_by_signing_in_can_do_when_it_is_not_asked(): void
    {
        config(['api.sign_in.scopes' => ['profile:read', 'polls:vote']]);

        $this->signIn()->assertCreated()->assertJson(['abilities' => ['profile:read', 'polls:vote']]);
    }

    public function test_a_site_can_give_keys_made_by_signing_in_every_scope_as_it_used_to(): void
    {
        config(['api.sign_in.scopes' => ['*']]);

        $this->signIn()->assertCreated()->assertJson(['abilities' => ['*']]);

        $this->assertSame(['*'], PersonalAccessToken::query()->sole()->abilities);
    }

    public function test_a_site_that_sets_no_default_scope_still_gives_the_least_there_is(): void
    {
        config(['api.sign_in.scopes' => []]);

        $this->signIn()->assertCreated()->assertJson(['abilities' => ['profile:read']]);
    }

    public function test_the_default_scopes_are_only_for_a_request_that_does_not_ask(): void
    {
        config(['api.sign_in.scopes' => ['profile:read']]);

        $this->signIn(['abilities' => ['polls:vote', 'support:read']])->assertCreated()->assertJson(['abilities' => ['polls:vote', 'support:read']]);
    }

    public function test_a_key_made_by_signing_in_cannot_do_what_it_was_not_given(): void
    {
        $secret = $this->signIn()->assertCreated()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->postJson('/api/v1/polls/1/vote')->assertForbidden()->assertJson(['scope' => 'polls:vote']);
    }

    public function test_a_key_made_by_signing_in_lasts_ninety_days_unless_the_site_says_otherwise(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));

        $this->signIn()->assertCreated();

        $this->assertTrue(PersonalAccessToken::query()->sole()->expires_at->equalTo(now()->addDays(90)));
    }

    public function test_the_site_chooses_how_long_a_key_made_by_signing_in_lasts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        config(['api.sign_in.expiry_days' => 7]);

        $response = $this->signIn()->assertCreated();

        $this->assertTrue(PersonalAccessToken::query()->sole()->expires_at->equalTo(now()->addDays(7)));
        $this->assertSame('2026-10-22', substr((string) $response->json('expires_at'), 0, 10));
    }

    public function test_a_site_can_have_keys_made_by_signing_in_that_never_end(): void
    {
        foreach ([null, 0, '', '0'] as $setting) {
            PersonalAccessToken::query()->delete();
            $this->app['auth']->forgetGuards();
            config(['api.sign_in.expiry_days' => $setting, 'api.keys.allow_no_expiry' => true]);

            $this->signIn()->assertCreated()->assertJson(['expires_at' => null]);

            $this->assertNull(PersonalAccessToken::query()->sole()->expires_at, 'a setting of '.var_export($setting, true));

            User::query()->delete();
        }
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

    // --- The same limits as a key made in Settings --------------------------------------------------------------------------------------

    public function test_a_key_made_by_signing_in_counts_toward_the_limit_on_keys(): void
    {
        config(['api.keys.max_active' => 2]);
        $user = User::factory()->create();

        $this->signInAs($user)->assertCreated();
        $this->signInAs($user)->assertCreated();
        $this->signInAs($user)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit' => 'You already have 2 API keys you can use. Revoke one you no longer need, then make another.']);

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_keys_made_in_settings_and_by_signing_in_share_the_limit(): void
    {
        config(['api.keys.max_active' => 2]);
        $user = User::factory()->create();

        app(ApiKeys::class)->create($user, 'From settings', ['profile:read'], 30);
        app(ApiKeys::class)->create($user, 'From settings too', ['profile:read'], 30);

        $this->signInAs($user)->assertUnprocessable()->assertJsonValidationErrors('limit');

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_the_limit_is_each_persons_own(): void
    {
        config(['api.keys.max_active' => 1]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->signInAs($first)->assertCreated();
        $this->signInAs($second)->assertCreated();
    }

    public function test_giving_a_key_back_makes_room_for_another(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();

        $secret = $this->signInAs($user)->assertCreated()->json('token');
        $this->signInAs($user)->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->signInAs($user)->assertCreated();
    }

    public function test_where_the_site_asks_for_a_verified_address_an_unverified_person_gets_no_key(): void
    {
        SystemSetting::set('email_verification_required', true);
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        $this->signInAs($unverified)->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Verify your email address first: API keys can only be made once it is verified.']);
        $this->assertSame(0, PersonalAccessToken::query()->count());

        $this->signInAs($verified)->assertCreated();
    }

    public function test_an_unverified_person_gets_a_key_where_the_site_does_not_ask(): void
    {
        SystemSetting::set('email_verification_required', false);

        $this->signInAs(User::factory()->unverified()->create())->assertCreated();
    }

    public function test_a_key_made_by_signing_in_ends_where_the_site_does_not_allow_one_that_never_does(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        config(['api.sign_in.expiry_days' => null, 'api.keys.allow_no_expiry' => false, 'api.keys.default_expiry_days' => 30]);

        $secret = $this->signIn()->assertCreated()->json('token');

        $key = PersonalAccessToken::query()->sole();
        $this->assertTrue($key->expires_at->equalTo(now()->addDays(30)));

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_signing_in_is_rate_limited_per_address_and_place(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->signInAs($user, ['password' => 'wrong'])->assertUnprocessable();
        }

        $this->signInAs($user)->assertStatus(429);
        $this->signInAs($other)->assertCreated();

        $this->travel(61)->seconds();

        $this->signInAs($user)->assertCreated();
    }

    public function test_signing_in_is_limited_by_the_hour_as_well(): void
    {
        config(['api.keys.max_active' => 1000]);
        $user = User::factory()->create();

        for ($round = 0; $round < 4; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $this->signInAs($user)->assertCreated();
            }

            $this->travel(61)->seconds();
        }

        $this->signInAs($user)->assertStatus(429);
    }

    public function test_the_same_address_from_somewhere_else_has_its_own_allowance(): void
    {
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($i = 0; $i < 5; $i++) {
            $this->signInAs($user, ['password' => 'wrong'])->assertUnprocessable();
        }

        $this->signInAs($user)->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);

        $this->signInAs($user)->assertCreated();
    }

    public function test_an_address_is_counted_whatever_the_case_it_is_typed_in(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->signInAs($user, ['email' => $i % 2 ? 'JANE@example.com' : 'jane@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->signInAs($user, ['email' => 'Jane@Example.com'])->assertStatus(429);
    }

    public function test_one_place_cannot_guess_passwords_across_many_addresses(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/v1/auth/token', ['email' => "guess{$i}@example.com", 'password' => 'wrong'])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/token', ['email' => 'one-more@example.com', 'password' => 'wrong']);

        $response->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));

        // Nobody signs in from there for the rest of the minute, with the right password either.
        $this->signInAs($user)->assertStatus(429);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_limit_on_a_place_is_that_places_own_and_ends_after_a_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/v1/auth/token', ['email' => "guess{$i}@example.com", 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->signInAs($user)->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        $this->signInAs($user)->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(61)->seconds();
        $this->signInAs($user)->assertCreated();
    }

    public function test_an_address_that_is_refused_does_not_use_up_the_places_allowance(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->signInAs($user, ['password' => 'wrong'])->assertUnprocessable();
        }

        // Ten more tries at the same address are all turned away by its own limit first, so they cost the place nothing.
        for ($i = 0; $i < 10; $i++) {
            $this->signInAs($user)->assertStatus(429);
        }

        // That leaves 15 of the place's 20 for everybody else.
        for ($i = 1; $i <= 15; $i++) {
            $this->postJson('/api/v1/auth/token', ['email' => "guess{$i}@example.com", 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/token', ['email' => 'one-more@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_an_email_that_is_not_text_is_a_validation_error_not_a_server_error(): void
    {
        $this->postJson('/api/v1/auth/token', ['email' => ['jane@example.com'], 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, PersonalAccessToken::query()->count());
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
