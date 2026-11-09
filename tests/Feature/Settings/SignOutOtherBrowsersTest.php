<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\ApplyImpersonation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A new password has to end the sessions that were started with the old one. Until `AuthenticateSession` was in the web group, a member
 * who changed their password because a device was stolen left the thief signed in, whatever the session driver. The test client is one
 * browser, so "another browser" is a session that holds the password hash the account had when it signed in (`otherBrowser()`).
 * docs/two-factor.md, "Browser sessions".
 */
class SignOutOtherBrowsersTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a-new-password-1';

    /**
     * What a session holds for an account's password: the HMAC of its hash, which is how `AuthenticateSession` stores it.
     */
    private function hashOf(User $user): string
    {
        $guard = Auth::guard('web');
        $this->assertTrue(method_exists($guard, 'hashPasswordForCookie'));

        return $guard->hashPasswordForCookie($user->getAuthPassword());
    }

    /**
     * A browser that signed in while the account had the password it has now.
     *
     * @return array<string, string>
     */
    private function otherBrowser(User $user): array
    {
        return ['password_hash_web' => $this->hashOf($user)];
    }

    /**
     * Ask for a page the way the other browser does, with its own session and nothing else of this client's.
     *
     * @param  array<string, string>  $session
     * @param  array<string, string>  $headers
     */
    private function asOtherBrowser(User $user, array $session, array $headers = []): TestResponse
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh())->withSession($session)->get(route('dashboard'), $headers);
    }

    private function usingDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
    }

    /**
     * @return array{id: string, user_id: int, ip_address: string, user_agent: string, payload: string, last_activity: int}
     */
    private function sessionRow(User $user): array
    {
        return [
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Some other browser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ];
    }

    // --- A session that was started before the password changed ---------------------------------------------------------------------

    #[Test]
    public function a_session_started_with_the_old_password_is_signed_out(): void
    {
        $user = User::factory()->create();
        $other = $this->otherBrowser($user);

        $user->update(['password' => Hash::make(self::NEW_PASSWORD)]);

        $this->asOtherBrowser($user, $other)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function a_page_visit_from_that_browser_is_sent_to_the_sign_in_page_and_not_given_an_error(): void
    {
        $user = User::factory()->create();
        $other = $this->otherBrowser($user);

        $user->update(['password' => Hash::make(self::NEW_PASSWORD)]);

        // What the page's own link-following sends (Inertia): a redirect that it follows to the sign-in page.
        $this->asOtherBrowser($user, $other, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function a_session_that_has_not_stored_a_hash_yet_is_kept_and_stores_it(): void
    {
        // Every session that exists when a site upgrades is like this: nobody is signed out by the upgrade itself.
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('password_hash_web', $this->hashOf($user));
    }

    #[Test]
    public function a_session_stays_signed_in_while_the_password_does_not_change(): void
    {
        $user = User::factory()->create();
        $other = $this->otherBrowser($user);

        $this->asOtherBrowser($user, $other)->assertOk();
    }

    // --- Changing the password -------------------------------------------------------------------------------------------------------

    #[Test]
    public function changing_the_password_signs_out_the_other_browsers_and_keeps_this_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $other = $this->otherBrowser($user);

        $this->put(route('password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        // This browser is not asked to sign in again, now or on the next page.
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('security.edit'))->assertOk();

        $this->asOtherBrowser($user, $other)->assertRedirect(route('login'));
    }

    #[Test]
    public function a_password_that_is_refused_signs_nobody_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $other = $this->otherBrowser($user);

        $this->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->asOtherBrowser($user, $other)->assertOk();
    }

    #[Test]
    public function a_password_reset_by_email_signs_out_every_browser(): void
    {
        $user = User::factory()->create();
        $other = $this->otherBrowser($user);

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $this->asOtherBrowser($user, $other)->assertRedirect(route('login'));
    }

    #[Test]
    public function the_device_that_changed_the_password_is_given_a_remember_me_cookie_that_still_works(): void
    {
        // The cookie holds the password hash, so after the change this device's old one would be refused when its session ends, and the
        // member would have to sign in again for no reason.
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $guard = Auth::guard('web');
        $name = $guard->getRecallerName();
        $old = $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword());

        $response = $this->withCookie($name, $old)->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertSessionHasNoErrors()->assertCookie($name);

        $new = $response->getCookie($name, true, false)->getValue();
        $this->assertNotSame($old, $new);
        $this->assertStringEndsWith('|'.$guard->hashPasswordForCookie($user->fresh()->getAuthPassword()), $new);

        // And the new one signs in, in a browser that has nothing else.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withCookie($name, $new)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function a_remember_me_cookie_from_before_the_change_does_not_sign_anyone_in(): void
    {
        // The cookie is a credential that outlives the session. It holds the password hash too, and is checked against it.
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $guard = Auth::guard('web');
        $cookie = $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword());

        $this->withCookie($guard->getRecallerName(), $cookie)->get(route('dashboard'))->assertOk();

        $user->update(['password' => Hash::make(self::NEW_PASSWORD)]);
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($guard->getRecallerName(), $cookie)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // --- "Sign out other browsers" ---------------------------------------------------------------------------------------------------

    #[Test]
    public function the_button_asks_for_the_password_and_signs_nobody_out_without_it(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $other = $this->otherBrowser($user);

        $this->post(route('security.sessions.destroy-others'), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->post(route('security.sessions.destroy-others'), [])->assertSessionHasErrors('password');

        $this->assertSame($before, $user->fresh()->password, 'nothing was changed');
        $this->asOtherBrowser($user, $other)->assertOk();
    }

    #[Test]
    public function with_the_password_it_signs_the_other_browsers_out_and_keeps_this_one_and_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $other = $this->otherBrowser($user);
        $before = $user->password;

        $this->post(route('security.sessions.destroy-others'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'other-sessions-ended');

        $this->assertNotSame($before, $user->fresh()->password, 'the hash is what the sessions are checked against');
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'and the password is the same one');

        $this->get(route('dashboard'))->assertOk();
        $this->asOtherBrowser($user, $other)->assertRedirect(route('login'));
    }

    #[Test]
    public function the_button_needs_a_signed_in_member(): void
    {
        $this->post(route('security.sessions.destroy-others'), ['password' => 'password'])->assertRedirect(route('login'));
    }

    // --- Which session drivers can list ----------------------------------------------------------------------------------------------

    #[Test]
    public function a_driver_that_cannot_name_sessions_lists_none_and_does_not_pretend_to_end_one(): void
    {
        // The test environment's driver is `array`, like Redis or files: no row says whose a session is. The table still holds what was
        // left from before a switch, and showing it would list browsers that are not open and "end" ones that are.
        $user = User::factory()->create();
        $row = $this->sessionRow($user);
        DB::table('sessions')->insert($row);

        $this->actingAs($user)->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('canListSessions', false)->where('sessions', []));

        $this->delete(route('security.sessions.destroy', $row['id']))->assertSessionHas('status', 'sessions-not-listed');
        $this->assertDatabaseHas('sessions', ['id' => $row['id']]);
    }

    #[Test]
    public function the_button_works_with_a_driver_that_cannot_name_sessions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $other = $this->otherBrowser($user);

        $this->post(route('security.sessions.destroy-others'), ['password' => 'password'])->assertSessionHas('status', 'other-sessions-ended');

        $this->asOtherBrowser($user, $other)->assertRedirect(route('login'));
    }

    #[Test]
    public function the_database_driver_lists_the_accounts_own_sessions_only(): void
    {
        $this->usingDatabaseSessions();

        $user = User::factory()->create();
        $mine = $this->sessionRow($user);
        $someoneElses = $this->sessionRow(User::factory()->create());
        DB::table('sessions')->insert([$mine, $someoneElses]);

        $this->actingAs($user)->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canListSessions', true)
                ->where('sessions', fn ($sessions) => collect($sessions)->pluck('id')->contains($mine['id']) && ! collect($sessions)->pluck('id')->contains($someoneElses['id'])));
    }

    #[Test]
    public function the_database_driver_ends_one_session_and_only_the_accounts_own(): void
    {
        $this->usingDatabaseSessions();

        $user = User::factory()->create();
        $mine = $this->sessionRow($user);
        $someoneElses = $this->sessionRow(User::factory()->create());
        DB::table('sessions')->insert([$mine, $someoneElses]);

        $this->actingAs($user)->delete(route('security.sessions.destroy', $someoneElses['id']))->assertRedirect();
        $this->assertDatabaseHas('sessions', ['id' => $someoneElses['id']]);

        $this->delete(route('security.sessions.destroy', $mine['id']))->assertSessionHas('status', 'session-revoked');
        $this->assertDatabaseMissing('sessions', ['id' => $mine['id']]);
    }

    #[Test]
    public function with_the_database_driver_the_button_also_clears_the_list_at_once(): void
    {
        $this->usingDatabaseSessions();

        $user = User::factory()->create();
        $someoneElses = $this->sessionRow(User::factory()->create());
        $rows = [$this->sessionRow($user), $this->sessionRow($user)];
        DB::table('sessions')->insert([...$rows, $someoneElses]);

        $this->actingAs($user)->post(route('security.sessions.destroy-others'), ['password' => 'password'])
            ->assertSessionHas('status', 'other-sessions-ended');

        foreach ($rows as $row) {
            $this->assertDatabaseMissing('sessions', ['id' => $row['id']]);
        }

        $this->assertDatabaseHas('sessions', ['id' => $someoneElses['id']]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count(), 'this browser is the one left');
    }

    #[Test]
    public function with_the_database_driver_the_browser_that_asked_keeps_its_own_row_and_its_session(): void
    {
        // The session handler updates a row it read at the start of the request. If the button deleted this browser's own row, that
        // update would find nothing, the session would not be saved, and the member would be signed out of the browser they asked from.
        $this->usingDatabaseSessions();

        $user = User::factory()->create();
        $id = Str::random(40);
        $this->withCookie(config('session.cookie'), $id);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);

        $this->post(route('security.sessions.destroy-others'), ['password' => 'password'])->assertSessionHas('status', 'other-sessions-ended');

        $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);
        $this->get(route('dashboard'))->assertOk();
    }

    // --- The password is checked, so the tries are counted ---------------------------------------------------------------------------

    #[Test]
    public function the_button_counts_wrong_passwords_and_stops_after_five_a_minute(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $try) {
            $this->actingAs($user)->post(route('security.sessions.destroy-others'), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        }

        $this->post(route('security.sessions.destroy-others'), ['password' => 'password'])->assertStatus(429);
    }

    #[Test]
    public function the_password_page_counts_wrong_passwords_too(): void
    {
        $user = User::factory()->create();
        $form = [
            'current_password' => 'wrong-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        foreach (range(1, 5) as $try) {
            $this->actingAs($user)->put(route('password.update'), $form)->assertSessionHasErrors('current_password');
        }

        $this->put(route('password.update'), [...$form, 'current_password' => 'password'])->assertStatus(429);
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'the right password is not accepted either, once the limit is reached');
    }

    // --- Where it runs ---------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_check_runs_after_the_session_starts_and_before_a_view_as_a_customer(): void
    {
        // After `ApplyImpersonation` it would see the customer (a view makes the request theirs), whose password hash a staff member's
        // session never held, and a staff member would be signed out the moment a view began.
        $router = app('router');
        $checked = 0;

        foreach (['dashboard', 'security.edit', 'login', 'home', 'impersonation.stop'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', $router->gatherRouteMiddleware($route));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(AuthenticateSession::class), "{$name}: the web group has it");
            $this->assertLessThan($at(AuthenticateSession::class), $at(StartSession::class), "{$name}: the session is read");
            $this->assertLessThan($at(ApplyImpersonation::class), $at(AuthenticateSession::class), "{$name}: before the view as a customer");

            $checked++;
        }

        $this->assertSame(5, $checked);
    }
}
