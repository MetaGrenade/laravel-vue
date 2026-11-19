<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Members\EmailIgnoringCaseUserProvider;
use App\Support\Members\EmailLookup;
use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;

/**
 * An email address finds the account it names whatever its case: signing in, the API sign-in, a password reset and a provider that
 * has verified the address.
 *
 * MySQL and MariaDB compare text without regard to case, so there `Ada@Example.com` finds `ada@example.com`. SQLite and PostgreSQL compare
 * exactly and found nothing: a phone that capitalises the first letter of what is typed, or an address an administrator wrote in other
 * letters, locked the person out. These tests run on SQLite, which compares exactly, so they fail where a lookup leaves the case to the
 * database. (Registration asks for lowercase, so most stored addresses are; a staff-made one, or one a provider handed over, may not be.)
 */
class EmailLookupIgnoresCaseTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-password-123';

    // --- Signing in --------------------------------------------------------------------------------------

    public function test_an_address_typed_in_other_letters_signs_in(): void
    {
        $staffMade = $this->member('Ada@Example.com', self::PASSWORD);

        $this->post('/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($staffMade);

        $this->post('/logout');
        $this->assertGuest();

        $registered = $this->member('grace@example.com', self::PASSWORD);

        $this->post('/login', ['email' => 'GRACE@Example.COM', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($registered);
    }

    public function test_a_wrong_password_is_refused_whatever_the_case_of_the_address(): void
    {
        $this->member('ada@example.com', self::PASSWORD);

        $this->post('/login', ['email' => 'ADA@example.com', 'password' => 'not-the-password']);

        $this->assertGuest();
    }

    public function test_an_address_no_one_has_is_refused(): void
    {
        $this->member('ada@example.com', self::PASSWORD);

        $this->post('/login', ['email' => 'adam@example.com', 'password' => self::PASSWORD]);

        $this->assertGuest();
    }

    public function test_the_exact_address_of_one_of_two_accounts_that_differ_by_case_signs_in_that_one(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        // A site that grew case variants before names were compared without regard to case: each keeps signing in as itself.
        $upper = $this->member('Ada@Example.com', 'the-upper-password-1');
        $lower = $this->member('ada@example.com', 'the-lower-password-1');

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'the-lower-password-1']);
        $this->assertAuthenticatedAs($lower);

        $this->post('/logout');

        $this->post('/login', ['email' => 'Ada@Example.com', 'password' => 'the-upper-password-1']);
        $this->assertAuthenticatedAs($upper);
    }

    public function test_a_spelling_that_could_mean_either_of_two_accounts_signs_in_neither(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        $this->member('Ada@Example.com', 'the-upper-password-1');
        $this->member('ada@example.com', 'the-lower-password-1');

        // The password is right for one of them, but it is not known which account was meant.
        $this->post('/login', ['email' => 'ADA@EXAMPLE.COM', 'password' => 'the-lower-password-1']);

        $this->assertGuest();
    }

    // --- The API sign-in ----------------------------------------------------------------------------------

    public function test_the_api_sign_in_finds_an_account_whatever_the_case_of_the_address(): void
    {
        $user = $this->member('Ada@Example.com', self::PASSWORD);

        $response = $this->postJson('/api/v1/auth/token', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'device_name' => 'phone']);

        $response->assertCreated()->assertJsonPath('user.id', $user->id);
        // What comes back is the address as it is stored.
        $response->assertJsonPath('user.email', 'Ada@Example.com');
    }

    public function test_the_api_sign_in_refuses_a_wrong_password_and_an_unknown_address(): void
    {
        $this->member('ada@example.com', self::PASSWORD);

        $this->postJson('/api/v1/auth/token', ['email' => 'ADA@example.com', 'password' => 'not-the-password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/token', ['email' => 'adam@example.com', 'password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_the_api_sign_in_does_not_guess_between_two_accounts_that_differ_by_case(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        $upper = $this->member('Ada@Example.com', 'the-upper-password-1');
        $this->member('ada@example.com', 'the-lower-password-1');

        $this->postJson('/api/v1/auth/token', ['email' => 'ADA@EXAMPLE.COM', 'password' => 'the-upper-password-1'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/token', ['email' => 'Ada@Example.com', 'password' => 'the-upper-password-1'])->assertCreated()->assertJsonPath('user.id', $upper->id);
    }

    // --- Resetting a password -----------------------------------------------------------------------------

    public function test_a_reset_link_is_sent_to_the_account_whatever_the_case_of_the_address_asked_for(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/forgot-password', ['email' => 'Ada@Example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_no_reset_link_is_sent_for_an_address_no_one_has(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/forgot-password', ['email' => 'adam@example.com']);

        Notification::assertNothingSent();
    }

    public function test_no_reset_link_is_sent_when_the_address_could_mean_either_of_two_accounts(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        Notification::fake();

        User::factory()->create(['email' => 'Ada@Example.com']);
        User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/forgot-password', ['email' => 'ADA@EXAMPLE.COM']);

        Notification::assertNothingSent();
    }

    public function test_a_password_can_be_reset_with_the_address_typed_in_other_letters(): void
    {
        $user = $this->member('Ada@Example.com', 'the-old-password-1');
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_reset_token_still_belongs_to_one_account_only(): void
    {
        $ada = $this->member('ada@example.com', 'the-old-password-1');
        $grace = $this->member('grace@example.com', 'the-old-password-2');
        $token = Password::createToken($ada);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'GRACE@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('the-old-password-1', $ada->fresh()->password));
        $this->assertTrue(Hash::check('the-old-password-2', $grace->fresh()->password));
    }

    // --- A provider that has verified the address ----------------------------------------------------------

    public function test_a_verified_provider_address_in_other_letters_signs_in_to_the_account_that_has_it(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->signInWith(new OAuthUser(id: 'discord-1', nickname: 'someone', name: 'Someone', email: 'Ada@Example.com', avatar: null, raw: [], emailVerified: true));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count(), 'a second account was made for the same mailbox');
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'discord', 'provider_id' => 'discord-1']);
    }

    public function test_an_address_the_provider_has_not_verified_never_finds_an_account_whatever_its_case(): void
    {
        $victim = User::factory()->create(['email' => 'ada@example.com']);

        $this->signInWith(new OAuthUser(id: 'discord-2', nickname: 'someone', name: 'Someone', email: 'ADA@example.com', avatar: null, raw: [], emailVerified: false));

        $this->assertAuthenticated();
        $this->assertNotSame($victim->id, auth()->id());
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $victim->id]);
    }

    public function test_a_verified_provider_address_that_could_mean_either_of_two_accounts_finds_neither(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        $upper = User::factory()->create(['email' => 'Ada@Example.com']);
        $lower = User::factory()->create(['email' => 'ada@example.com']);

        $this->signInWith(new OAuthUser(id: 'discord-3', nickname: 'someone', name: 'Someone', email: 'ADA@EXAMPLE.COM', avatar: null, raw: [], emailVerified: true));

        $this->assertAuthenticated();
        $this->assertNotContains(auth()->id(), [$upper->id, $lower->id], 'one of two accounts was picked');
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $upper->id]);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $lower->id]);
    }

    // --- The lookup on its own ----------------------------------------------------------------------------

    public function test_the_lookup_finds_one_account_in_any_case_and_none_for_what_nobody_has(): void
    {
        $ada = User::factory()->create(['email' => 'Ada@Example.com']);

        foreach (['Ada@Example.com', 'ada@example.com', 'ADA@EXAMPLE.COM'] as $given) {
            $this->assertTrue($ada->is(EmailLookup::find($given)), "{$given} did not find the account");
            $this->assertSame('Ada@Example.com', EmailLookup::storedAs($given), "{$given} was not given back as stored");
        }

        $this->assertNull(EmailLookup::find('adam@example.com'));
        $this->assertNull(EmailLookup::storedAs('adam@example.com'));
        $this->assertNull(EmailLookup::find(''));
    }

    public function test_the_lookup_prefers_the_exact_address_and_does_not_guess_between_two(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        $upper = User::factory()->create(['email' => 'Ada@Example.com']);
        $lower = User::factory()->create(['email' => 'ada@example.com']);

        $this->assertTrue($upper->is(EmailLookup::find('Ada@Example.com')));
        $this->assertTrue($lower->is(EmailLookup::find('ada@example.com')));
        $this->assertNull(EmailLookup::find('aDA@example.com'));
    }

    public function test_the_lookup_does_not_read_an_address_as_a_pattern(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->assertNull(EmailLookup::find('%@example.com'));
        $this->assertNull(EmailLookup::find('ada_@example.com'));
    }

    public function test_an_address_nobody_has_is_asked_once_where_the_database_ignores_case_and_twice_where_it_does_not(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        DB::enableQueryLog();
        EmailLookup::find('nobody@example.com');
        $asked = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Every wrong address typed on the sign-in page comes here: where the exact match already ignores case there is nothing more to ask.
        $this->assertSame(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 1 : 2, $asked);
    }

    public function test_an_address_typed_right_is_asked_once_on_every_database(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        DB::enableQueryLog();
        EmailLookup::find('ada@example.com');
        $asked = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $asked, 'the address as typed is the first and, when it is right, the only question');
    }

    public function test_the_site_signs_people_in_through_the_provider_that_ignores_case(): void
    {
        // `Auth::attempt()` and the password broker read this provider: if the driver in config/auth.php is put back, every sign-in above goes
        // back to the exact match.
        $this->assertSame('eloquent-ignoring-case', config('auth.providers.users.driver'));
        $this->assertInstanceOf(EmailIgnoringCaseUserProvider::class, Auth::createUserProvider('users'));
        // The password broker asks for the same provider by name.
        $this->assertSame('users', config('auth.passwords.users.provider'));
    }

    public function test_the_provider_keeps_every_other_credential_it_is_given(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'is_banned' => true]);
        $provider = Auth::createUserProvider('users');

        $this->assertNull($provider->retrieveByCredentials(['email' => 'ADA@example.com', 'is_banned' => false]), 'a second credential was ignored');
        $this->assertNotNull($provider->retrieveByCredentials(['email' => 'ADA@example.com', 'is_banned' => true]));
        $this->assertNull($provider->retrieveByCredentials(['password' => 'only-a-password']), 'a password alone found an account');
    }

    /** A member who can sign in: the factory stores the password as it is given, so it is hashed here. */
    private function member(string $email, string $password): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make($password)]);
    }

    /**
     * MySQL and MariaDB refuse a second row whose address differs only by case (the unique index compares without regard to case), so a
     * site on them cannot have a case variant to begin with.
     */
    private function skipWhereTheDatabaseRefusesCaseVariants(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL and MariaDB compare addresses without regard to case, so no row can have a case variant.');
        }
    }

    private function signInWith(OAuthUser $oauthUser): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($oauthUser);

        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('driver')->with('discord')->andReturn($provider);

        $this->instance(OAuthManager::class, $manager);

        $this->get(route('oauth.callback', ['provider' => 'discord']));
    }
}
