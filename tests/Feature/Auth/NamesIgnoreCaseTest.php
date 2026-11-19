<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\PricingController;
use App\Models\User;
use App\Rules\UniqueIgnoringCase;
use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthUser;
use App\Support\Security\SignInMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A nickname or an email address that differs from another only by case is the same one, on every database.
 *
 * MySQL and MariaDB compare text without regard to case, so `unique` already refused `ada` when `Ada` was taken there. SQLite and
 * PostgreSQL compare exactly and let both through: two members whose names differ only by case (one passes for the other), or two accounts
 * for one mailbox. The tests run on SQLite, which compares exactly, so they fail where a rule leaves the case to the database.
 */
class NamesIgnoreCaseTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-password-123';

    public function test_a_name_that_differs_only_by_case_is_taken(): void
    {
        User::factory()->create(['nickname' => 'Ada']);

        foreach (['Ada', 'ada', 'ADA', 'aDa'] as $name) {
            $this->assertTrue(UniqueIgnoringCase::taken('users', 'nickname', $name), "{$name} was free");
        }

        foreach (['Adam', 'Ad', 'Grace'] as $name) {
            $this->assertFalse(UniqueIgnoringCase::taken('users', 'nickname', $name), "{$name} was taken");
        }
    }

    public function test_the_rule_refuses_a_taken_name_with_the_message_of_unique(): void
    {
        User::factory()->create(['nickname' => 'Ada']);

        $validator = Validator::make(['nickname' => 'ada'], ['nickname' => [UniqueIgnoringCase::in('users', 'nickname')]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('The nickname has already been taken.', $validator->errors()->first('nickname'));
        $this->assertFalse(Validator::make(['nickname' => 'Grace'], ['nickname' => [UniqueIgnoringCase::in('users', 'nickname')]])->fails());
    }

    public function test_the_row_being_changed_does_not_count_against_itself(): void
    {
        $ada = User::factory()->create(['nickname' => 'Ada']);
        $rule = fn (string $value) => Validator::make(['nickname' => $value], ['nickname' => [UniqueIgnoringCase::in('users', 'nickname')->ignore($ada->id)]])->fails();

        $this->assertFalse($rule('Ada'));
        $this->assertFalse($rule('ada'), 'a member can change only the case of their own name');

        User::factory()->create(['nickname' => 'Grace']);
        $this->assertTrue($rule('GRACE'));
    }

    public function test_a_member_who_already_has_a_case_variant_can_keep_their_name_but_not_take_another_like_it(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        // A site that grew case variants before this rule (SQLite and PostgreSQL let them in).
        User::factory()->create(['nickname' => 'Ada']);
        $lower = User::factory()->create(['nickname' => 'ada']);
        $rule = fn (string $value) => Validator::make(['nickname' => $value], ['nickname' => [UniqueIgnoringCase::in('users', 'nickname')->ignore($lower->id)]])->fails();

        $this->assertFalse($rule('ada'), 'the name the member already has was refused');
        $this->assertTrue($rule('ADA'), 'a new case variant of a name another member has was accepted');
        $this->assertFalse($rule('Grace'));
    }

    public function test_a_value_that_is_not_text_is_left_to_the_other_rules(): void
    {
        User::factory()->create(['nickname' => 'Ada']);

        foreach ([null, '', ['Ada'], 12] as $value) {
            $this->assertFalse(Validator::make(['nickname' => $value], ['nickname' => [UniqueIgnoringCase::in('users', 'nickname')]])->fails(), 'the rule judged '.json_encode($value));
        }
    }

    public function test_registering_with_a_nickname_that_differs_only_by_case_is_refused(): void
    {
        User::factory()->create(['nickname' => 'Ada', 'email' => 'first@example.com']);

        $this->post(route('register'), ['nickname' => 'ada', 'email' => 'other@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasErrors('nickname');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_registering_with_an_address_that_differs_only_by_case_from_one_a_member_has_is_refused(): void
    {
        // An address kept in capitals (made in the admin area, which did not lowercase it): the form that registers asks for lowercase.
        User::factory()->create(['nickname' => 'Ada', 'email' => 'Ada@Example.com']);

        $this->post(route('register'), ['nickname' => 'Grace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_a_member_cannot_change_their_nickname_to_one_that_differs_only_by_case(): void
    {
        User::factory()->create(['nickname' => 'Ada']);
        $grace = User::factory()->create(['nickname' => 'Grace']);

        $this->actingAs($grace)->patch(route('profile.update'), ['nickname' => 'ADA', 'email' => $grace->email])
            ->assertSessionHasErrors('nickname');

        $this->assertSame('Grace', $grace->fresh()->nickname);
    }

    public function test_a_member_who_has_a_case_variant_can_still_save_their_settings_with_the_name_unchanged(): void
    {
        $this->skipWhereTheDatabaseRefusesCaseVariants();

        User::factory()->create(['nickname' => 'Ada']);
        $lower = User::factory()->create(['nickname' => 'ada']);

        $this->actingAs($lower)->patch(route('profile.update'), ['nickname' => 'ada', 'email' => $lower->email, 'profile_bio' => 'Hello'])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('Hello', $lower->fresh()->profile_bio);
    }

    public function test_staff_cannot_give_a_member_a_nickname_or_an_address_that_differs_only_by_case(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        User::factory()->create(['nickname' => 'Ada', 'email' => 'Ada@Example.com']);
        $grace = User::factory()->create(['nickname' => 'Grace', 'email' => 'grace@example.com']);

        $this->actingAs($admin)->put(route('acp.users.update', $grace), ['nickname' => 'ADA', 'email' => 'grace@example.com', 'roles' => []])
            ->assertSessionHasErrors('nickname');
        $this->put(route('acp.users.update', $grace), ['nickname' => 'Grace', 'email' => 'ADA@example.com', 'roles' => []])
            ->assertSessionHasErrors('email');

        $this->assertSame(['Grace', 'grace@example.com'], [$grace->fresh()->nickname, $grace->fresh()->email]);
    }

    public function test_a_guest_who_subscribes_cannot_use_an_address_that_differs_only_by_case_from_a_members(): void
    {
        User::factory()->create(['email' => 'Ada@Example.com']);

        $this->postJson(route('pricing.intent'), ['plan_id' => 1, 'email' => 'ada@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_nickname_made_up_for_a_guest_who_subscribes_is_free_whatever_its_case(): void
    {
        User::factory()->create(['nickname' => 'Pat']);

        $method = new \ReflectionMethod(PricingController::class, 'createUserFromEmail');
        $user = $method->invoke(app(PricingController::class), 'pat@example.com');

        $this->assertSame('pat-1', $user->nickname);
    }

    public function test_the_nickname_made_up_for_someone_who_signs_in_with_a_provider_is_free_whatever_its_case(): void
    {
        User::factory()->create(['nickname' => 'DiscordUser']);

        $this->signInWith(new OAuthUser(id: 'discord-1', nickname: 'discorduser', name: 'discorduser', email: 'someone@example.com', avatar: null, raw: [], emailVerified: true));

        $this->assertSame(2, User::count());
        $this->assertNotSame('discorduser', User::where('email', 'someone@example.com')->value('nickname'));
        $this->assertSame('discorduser1', User::where('email', 'someone@example.com')->value('nickname'));
    }

    public function test_an_address_a_provider_hands_over_is_not_taken_when_it_differs_only_by_case_from_a_members(): void
    {
        User::factory()->create(['email' => 'Taken@Example.com']);

        $this->signInWith(new OAuthUser(id: 'discord-2', nickname: 'someone', name: 'Someone', email: 'taken@example.com', avatar: null, raw: [], emailVerified: false));

        $created = User::where('nickname', 'someone')->firstOrFail();
        $this->assertNotSame('taken@example.com', $created->email);
        $this->assertStringEndsWith('@'.SignInMethods::PLACEHOLDER_DOMAIN, $created->email);
    }

    /**
     * MySQL and MariaDB refuse a second row whose name differs only by case (the unique index compares without regard to case), so a site
     * on them cannot have a case variant to begin with.
     */
    private function skipWhereTheDatabaseRefusesCaseVariants(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL and MariaDB compare names without regard to case, so no row can have a case variant.');
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
