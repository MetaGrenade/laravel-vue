<?php

namespace Tests\Feature\Settings;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Notifications\ApiKeyCreated;
use App\Support\Api\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\NewAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Making or rotating an API key in Settings asks for the password. A key is a credential that goes on working after the browser that made
 * it is closed, so from a session that was left open, or taken, one click would hand over a way into the account that outlives the
 * session: the owner signs out, changes the password, and the thief's key still works until it expires. `POST /api/v1/auth/token` has
 * always asked for the password; the page now does the same. Revoking, removing and limiting a key never ask. docs/api-keys.md.
 */
class ApiKeyPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function madeUpAddress(): User
    {
        // What Steam, and a Discord account with no address, get: it looks like an email and receives nothing.
        return User::factory()->create(['email' => 'steam_76561198000000000@oauth.local']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return ['name' => 'Deploy script', 'scopes' => ['profile:read'], 'expires_in' => '90', ...$overrides];
    }

    private function make(User $user, array $form)
    {
        return $this->actingAs($user)->post(route('settings.api-keys.store'), $form);
    }

    private function keyOf(User $user): NewAccessToken
    {
        return $user->createToken('Deploy', ['profile:read'], now()->addDays(60));
    }

    private function rotate(User $user, PersonalAccessToken $key, array $form)
    {
        return $this->actingAs($user)->post(route('settings.api-keys.rotate', $key->id), ['grace_hours' => 0, ...$form]);
    }

    private function assertNothingWasMade(User $user, $response): void
    {
        $this->assertSame(0, $user->tokens()->count(), 'no key was made');
        $this->assertNull($response->getSession()->get('plain_text_token'), 'no secret was handed out');
        Notification::assertNothingSent();
    }

    // --- Making a key ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_is_not_made_without_the_password(): void
    {
        $user = User::factory()->create();

        $response = $this->make($user, $this->form());

        $response->assertSessionHasErrors(['current_password' => 'Enter your password to make or replace a key.']);
        $this->assertNothingWasMade($user, $response);
    }

    #[Test]
    public function an_empty_password_is_the_same_as_none(): void
    {
        $user = User::factory()->create();

        $response = $this->make($user, $this->form(['current_password' => '']));

        $response->assertSessionHasErrors(['current_password' => 'Enter your password to make or replace a key.']);
        $this->assertNothingWasMade($user, $response);
    }

    #[Test]
    public function a_wrong_password_makes_nothing_and_says_so(): void
    {
        $user = User::factory()->create();

        $response = $this->make($user, $this->form(['current_password' => 'not-my-password']));

        $response->assertSessionHasErrors(['current_password' => 'The password is incorrect.']);
        $this->assertNothingWasMade($user, $response);
    }

    #[Test]
    public function somebody_elses_password_is_not_mine(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['password' => Hash::make('another-persons-password')]);

        $response = $this->make($user, $this->form(['current_password' => 'another-persons-password']));

        $response->assertSessionHasErrors('current_password');
        $this->assertNothingWasMade($user, $response);
    }

    #[Test]
    public function with_the_password_a_key_is_made_and_the_owner_is_told(): void
    {
        $user = User::factory()->create();

        $response = $this->make($user, $this->form(['current_password' => 'password']));

        $response->assertSessionHasNoErrors()->assertRedirect(route('settings.api-keys.index'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotEmpty($response->getSession()->get('plain_text_token'));
        Notification::assertSentTo($user, ApiKeyCreated::class);
    }

    // --- Rotating a key --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_is_not_rotated_without_the_password_and_the_old_one_is_left_alone(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        // No grace period: with the password it would stop the old key at once, which a session that was taken must not be able to do either.
        $response = $this->rotate($user, $old->accessToken, []);

        $response->assertSessionHasErrors(['current_password' => 'Enter your password to make or replace a key.']);
        $this->assertSame(1, $user->tokens()->count(), 'no new key');
        $this->assertNull($old->accessToken->fresh()->revoked_at, 'the old key still works');
        $this->assertNull($old->accessToken->fresh()->rotated_at);
        $this->assertNull($response->getSession()->get('plain_text_token'));
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_wrong_password_rotates_nothing(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $response = $this->rotate($user, $old->accessToken, ['current_password' => 'not-my-password']);

        $response->assertSessionHasErrors(['current_password' => 'The password is incorrect.']);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull($old->accessToken->fresh()->revoked_at);
        $this->assertNull($response->getSession()->get('plain_text_token'));
        Notification::assertNothingSent();
    }

    #[Test]
    public function with_the_password_a_key_is_rotated(): void
    {
        $user = User::factory()->create();
        $old = $this->keyOf($user);

        $response = $this->rotate($user, $old->accessToken, ['current_password' => 'password']);

        $response->assertSessionHasNoErrors()->assertRedirect(route('settings.api-keys.index'));
        $this->assertSame(2, $user->tokens()->count());
        $this->assertNotNull($old->accessToken->fresh()->revoked_at);
        $this->assertNotEmpty($response->getSession()->get('plain_text_token'));
    }

    #[Test]
    public function the_password_does_not_open_somebody_elses_key(): void
    {
        $owner = User::factory()->create();
        $theirs = $this->keyOf($owner);

        $this->rotate(User::factory()->create(), $theirs->accessToken, ['current_password' => 'password'])->assertNotFound();

        $this->assertNull($theirs->accessToken->fresh()->revoked_at);
    }

    // --- What never asks -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function limiting_revoking_and_removing_a_key_ask_for_nothing(): void
    {
        $user = User::factory()->create();
        $key = $this->keyOf($user)->accessToken;

        $this->actingAs($user)->patch(route('settings.api-keys.limits', $key->id), ['hourly_quota' => 10, 'daily_quota' => 100])
            ->assertSessionHasNoErrors();
        $this->assertSame(10, $key->fresh()->hourly_quota);

        $this->actingAs($user)->post(route('settings.api-keys.revoke', $key->id))->assertSessionHasNoErrors();
        $this->assertNotNull($key->fresh()->revoked_at);

        $this->actingAs($user)->delete(route('settings.api-keys.destroy', $key->id))->assertSessionHasNoErrors();
        $this->assertNull(PersonalAccessToken::find($key->id));
    }

    #[Test]
    public function looking_at_the_keys_asks_for_nothing(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.api-keys.index'))->assertOk();
    }

    // --- A member whose address the site made up -------------------------------------------------------------------------------------

    #[Test]
    public function a_member_whose_address_the_site_made_up_is_not_asked(): void
    {
        $member = $this->madeUpAddress();

        $this->make($member, $this->form())->assertSessionHasNoErrors();
        $this->assertSame(1, $member->tokens()->count());

        $old = $member->tokens()->first();
        $this->rotate($member, $old, [])->assertSessionHasNoErrors();
        $this->assertSame(2, $member->tokens()->count());
    }

    #[Test]
    public function a_member_who_signed_up_with_a_provider_and_has_a_real_address_is_asked(): void
    {
        $member = User::factory()->create(['email' => 'real.person@example.com']);

        $this->make($member, $this->form())->assertSessionHasErrors('current_password');
    }

    #[Test]
    public function the_page_says_whether_it_will_ask(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('asks_for_password', true));

        $this->actingAs($this->madeUpAddress())->get(route('settings.api-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->where('asks_for_password', false));
    }

    #[Test]
    public function the_rule_is_one_place_and_a_guest_is_never_asked_anything_by_it(): void
    {
        $this->assertTrue(ApiKeys::asksForPassword(User::factory()->make()));
        $this->assertFalse(ApiKeys::asksForPassword($this->madeUpAddress()));
        $this->assertFalse(ApiKeys::asksForPassword(null));
        $this->assertSame([], ApiKeys::passwordRules(null));
        $this->assertSame(['current_password' => ['required', 'string', 'current_password']], ApiKeys::passwordRules(User::factory()->make()));
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->post(route('settings.api-keys.store'), $this->form(['current_password' => 'password']))->assertRedirect(route('login'));
    }

    // --- The password is checked, so the tries are counted ---------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function placesThatMakeAKey(): array
    {
        return ['making a key' => ['store'], 'rotating a key' => ['rotate']];
    }

    private function guess(User $user, string $place, string $password): TestResponse
    {
        if ($place === 'store') {
            return $this->make($user, $this->form(['current_password' => $password]));
        }

        return $this->rotate($user, $user->tokens()->firstOrFail(), ['current_password' => $password]);
    }

    #[Test]
    #[DataProvider('placesThatMakeAKey')]
    public function the_tries_are_counted_with_the_password_changes_five_a_minute_in_all(string $place): void
    {
        $user = User::factory()->create();
        $this->keyOf($user);

        // Three guesses at the password page, then two here: five. The key routes have a limit of their own (five a minute as well), so
        // reaching this count with only two of its own tries is the shared count at work.
        foreach (range(1, 3) as $try) {
            $this->actingAs($user)->put(route('password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'a-new-Password-1',
                'password_confirmation' => 'a-new-Password-1',
            ])->assertSessionHasErrors('current_password');
        }

        foreach (range(1, 2) as $try) {
            $this->guess($user, $place, 'wrong-password')->assertSessionHasErrors('current_password');
        }

        $this->guess($user, $place, 'password')->assertStatus(429);
        $this->assertSame(1, $user->tokens()->count(), 'the right password is not accepted either, once the limit is reached');
    }

    #[Test]
    #[DataProvider('placesThatMakeAKey')]
    public function five_wrong_guesses_here_use_up_the_five_a_minute_of_the_password_page_too(string $place): void
    {
        $user = User::factory()->create();
        $this->keyOf($user);

        foreach (range(1, 5) as $try) {
            $this->guess($user, $place, 'wrong-password')->assertSessionHasErrors('current_password');
        }

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-Password-1',
            'password_confirmation' => 'a-new-Password-1',
        ])->assertStatus(429);
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'the password was not changed');
    }

    #[Test]
    public function the_count_is_the_members_own(): void
    {
        $busy = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 5) as $try) {
            $this->make($busy, $this->form(['current_password' => 'wrong-password']))->assertSessionHasErrors('current_password');
        }

        $this->make($busy, $this->form(['current_password' => 'password']))->assertStatus(429);

        // Somebody else, from the same address, is not held up by it.
        $this->make($other, $this->form(['current_password' => 'password']))->assertSessionHasNoErrors();
        $this->assertSame(1, $other->tokens()->count());
    }

    #[Test]
    public function a_key_made_by_a_member_who_is_not_asked_is_not_a_guess_and_is_not_counted(): void
    {
        config(['api.keys.max_active' => 1000]);
        $member = $this->madeUpAddress();

        // Two keys made and two rotated: nothing was guessed, so none of the five tries the password page has are used.
        foreach (range(1, 2) as $try) {
            $this->make($member, $this->form(['name' => "Key {$try}"]))->assertSessionHasNoErrors();
        }

        foreach ($member->tokens()->get() as $key) {
            $this->rotate($member, $key, ['grace_hours' => 24])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, $member->tokens()->count());

        foreach (range(1, 5) as $try) {
            $this->actingAs($member)->put(route('password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'a-new-Password-1',
                'password_confirmation' => 'a-new-Password-1',
            ])->assertSessionHasErrors('current_password');
        }

        $this->actingAs($member)->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'a-new-Password-1',
            'password_confirmation' => 'a-new-Password-1',
        ])->assertStatus(429);
    }

    #[Test]
    public function limiting_and_revoking_are_not_guesses_and_are_not_counted(): void
    {
        $user = User::factory()->create();
        $keys = [$this->keyOf($user)->accessToken, $this->keyOf($user)->accessToken, $this->keyOf($user)->accessToken];

        foreach ($keys as $key) {
            $this->actingAs($user)->patch(route('settings.api-keys.limits', $key->id), ['hourly_quota' => 5, 'daily_quota' => 50])->assertSessionHasNoErrors();
            $this->actingAs($user)->post(route('settings.api-keys.revoke', $key->id))->assertSessionHasNoErrors();
        }

        foreach (range(1, 5) as $try) {
            $this->make($user, $this->form(['current_password' => 'wrong-password']))->assertSessionHasErrors('current_password');
        }
    }
}
