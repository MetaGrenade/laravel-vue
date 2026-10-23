<?php

namespace Tests\Feature\Admin;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin → Tokens: staff choose a token's abilities from the site's scopes (or full access), and revoking one stops it.
 */
class TokenScopesAcpTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    private function payload(User $owner, array $overrides = []): array
    {
        return [
            'name' => 'Integration',
            'user_id' => $owner->id,
            'abilities' => ['profile:read'],
            'expires_at' => null,
            'hourly_quota' => null,
            'daily_quota' => null,
            ...$overrides,
        ];
    }

    #[Test]
    public function the_page_offers_every_scope_the_site_has(): void
    {
        $this->signInAdmin();

        $this->get(route('acp.tokens.index'))->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->has('scopes', 7)
            ->where('scopes.0.key', 'profile:read'));
    }

    #[Test]
    public function a_token_is_made_with_scopes_chosen_from_the_site(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();

        $this->post(route('acp.tokens.store'), $this->payload($owner, ['abilities' => ['profile:read', 'blog:write', 'profile:read']]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('plain_text_token');

        $this->assertSame(['profile:read', 'blog:write'], PersonalAccessToken::query()->sole()->abilities);
    }

    #[Test]
    public function staff_can_give_full_access(): void
    {
        $this->signInAdmin();

        $this->post(route('acp.tokens.store'), $this->payload(User::factory()->create(), ['abilities' => ['*']]))->assertSessionHasNoErrors();

        $this->assertSame(['*'], PersonalAccessToken::query()->sole()->abilities);
    }

    #[Test]
    public function a_token_made_for_a_person_works_for_what_it_was_given(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();

        $secret = $this->post(route('acp.tokens.store'), $this->payload($owner))->getSession()->get('plain_text_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->postJson('/api/v1/polls/1/vote')->assertForbidden();
    }

    #[Test]
    public function abilities_must_be_chosen_and_must_be_ones_the_site_has(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();

        foreach ([
            'abilities' => [[], 'abilities'],
            'abilities.null' => [null, 'abilities'],
            'abilities.free text' => [['read', 'update'], 'abilities.0'],
            'abilities.one made up' => [['profile:read', 'delete-everything'], 'abilities.1'],
        ] as [$abilities, $error]) {
            $this->post(route('acp.tokens.store'), $this->payload($owner, ['abilities' => $abilities]))->assertSessionHasErrors($error);
        }

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function a_token_is_updated_to_other_scopes(): void
    {
        $this->signInAdmin();
        $token = User::factory()->create()->createToken('Old', ['profile:read'])->accessToken;

        $this->put(route('acp.tokens.update', $token->id), $this->payload($token->tokenable, ['name' => 'Renamed', 'abilities' => ['polls:vote', 'support:read', 'polls:vote']]))
            ->assertSessionHasNoErrors();

        $token->refresh();
        $this->assertSame('Renamed', $token->name);
        $this->assertSame(['polls:vote', 'support:read'], $token->abilities);
    }

    #[Test]
    public function an_update_that_chooses_nothing_is_refused_rather_than_leaving_a_token_that_does_nothing(): void
    {
        $this->signInAdmin();
        $token = User::factory()->create()->createToken('Old', ['profile:read'])->accessToken;

        $this->put(route('acp.tokens.update', $token->id), $this->payload($token->tokenable, ['abilities' => []]))->assertSessionHasErrors('abilities');
        $this->put(route('acp.tokens.update', $token->id), $this->payload($token->tokenable, ['abilities' => ['read']]))->assertSessionHasErrors('abilities.0');

        $this->assertSame(['profile:read'], $token->fresh()->abilities);
    }

    #[Test]
    public function revoking_a_token_here_really_stops_it(): void
    {
        $admin = $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $owner->createToken('Live', ['profile:read']);

        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertOk();

        // The request with a key left the default guard as Sanctum's; the staff member's own pages use the session's.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($admin);
        $this->patch(route('acp.tokens.revoke', $key->accessToken->id))->assertRedirect(route('acp.tokens.index'));

        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function restoring_a_revoked_token_makes_it_work_again(): void
    {
        $this->signInAdmin();
        $owner = User::factory()->create();
        $key = $owner->createToken('Revoked', ['profile:read']);
        $key->accessToken->forceFill(['revoked_at' => now()])->save();

        $this->put(route('acp.tokens.update', $key->accessToken->id), $this->payload($owner, ['name' => 'Revoked', 'clear_revocation' => true]))
            ->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        $this->withToken($key->plainTextToken)->getJson('/api/user')->assertOk();
    }
}
