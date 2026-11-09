<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Support\Security\EmailChange;
use App\Support\Security\SignInMethods;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A new email address is where "Forgot your password?" writes to, so changing it asks for the password and tells the address the
 * account had (docs/two-factor.md, "Changing the email address"). Until then a session that was left open or taken could change it,
 * ask for a reset at the new address and own the account.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email = 'original@example.com'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function save(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), [
            'nickname' => $user->nickname,
            'email' => $user->email,
            ...$overrides,
        ]);
    }

    // --- The password -----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_new_address_is_not_taken_without_the_password(): void
    {
        Notification::fake();
        $user = $this->member();

        $this->save($user, ['email' => 'new@example.com'])->assertSessionHasErrors('current_password');

        $this->assertSame('original@example.com', $user->fresh()->email);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_wrong_password_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->member();

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

        $this->assertSame('original@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at, 'it is still the verified address');
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_right_password_changes_the_address_and_asks_for_the_new_one_to_be_verified(): void
    {
        Notification::fake();
        $user = $this->member();
        $before = $user->getAuthPassword();

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'verification-link-sent');

        $user->refresh();

        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($before, $user->getAuthPassword(), 'the password that was typed is not stored as the password');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function the_password_is_not_a_field_of_the_account(): void
    {
        $user = $this->member();

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password', 'password' => 'a-new-password-1'])->assertSessionHasNoErrors();

        $this->assertTrue(password_verify('password', $user->fresh()->getAuthPassword()), 'a password sent with the form is not applied');
    }

    #[Test]
    public function the_password_that_was_typed_is_never_handed_to_the_model(): void
    {
        // Left alone, fill() drops what is not fillable without a word, so a field that later became fillable would be applied; with this
        // switched on it raises instead, which shows what the controller gives it.
        Model::preventSilentlyDiscardingAttributes();

        try {
            $user = $this->member();

            $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

            $this->assertSame('new@example.com', $user->fresh()->email);
        } finally {
            Model::preventSilentlyDiscardingAttributes(false);
        }
    }

    #[Test]
    public function a_save_that_leaves_the_address_alone_asks_for_nothing(): void
    {
        Notification::fake();
        $user = $this->member();

        $this->save($user, ['nickname' => 'Someone Else'])->assertSessionHasNoErrors();
        // Spaces around it, or an upper-case letter that the lowercase rule would refuse anyway, are not another address.
        $this->save($user->fresh(), ['nickname' => 'Someone Else Again', 'email' => '  original@example.com '])->assertSessionHasNoErrors();

        $this->assertSame('Someone Else Again', $user->fresh()->nickname);
        $this->assertSame('original@example.com', $user->fresh()->email);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_password_sent_with_an_unchanged_address_is_not_checked(): void
    {
        $user = $this->member();

        $this->save($user, ['nickname' => 'Same Address', 'current_password' => 'wrong-password'])->assertSessionHasNoErrors();

        $this->assertSame('Same Address', $user->fresh()->nickname);
    }

    #[Test]
    public function an_address_that_somebody_else_has_is_refused_even_with_the_password(): void
    {
        $this->member('taken@example.com');
        $user = $this->member();

        $this->save($user, ['email' => 'taken@example.com', 'current_password' => 'password'])->assertSessionHasErrors('email');

        $this->assertSame('original@example.com', $user->fresh()->email);
    }

    // --- A member whose address the site made up --------------------------------------------------------------------------------------------

    #[Test]
    public function a_member_whose_address_was_made_up_is_not_asked_and_is_how_they_get_a_real_one(): void
    {
        Notification::fake();
        $user = $this->member('steam_76561198000000000@'.SignInMethods::PLACEHOLDER_DOMAIN);

        $this->save($user, ['email' => 'real@example.com'])->assertSessionHasNoErrors();

        $this->assertSame('real@example.com', $user->fresh()->email);
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertNotSentTo($user, EmailAddressChanged::class);
    }

    #[Test]
    public function the_page_says_whether_a_new_address_asks_for_the_password(): void
    {
        $real = $this->member();
        $madeUp = $this->member('discord_1@'.SignInMethods::PLACEHOLDER_DOMAIN);

        $this->actingAs($real)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('emailChangeAsksForPassword', true));
        $this->actingAs($madeUp)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('emailChangeAsksForPassword', false));
    }

    // --- The address the account had --------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_address_the_account_had_is_told(): void
    {
        Notification::fake();
        $user = $this->member();

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, EmailAddressChanged::class, function (EmailAddressChanged $notice) {
            return $notice->oldEmail === 'original@example.com' && $notice->newEmail === 'new@example.com' && $notice->ip === '127.0.0.1';
        });
    }

    #[Test]
    public function it_reaches_the_old_address_and_names_the_new_one_only_in_part(): void
    {
        $user = $this->member('original@example.com');

        $this->save($user, ['email' => 'secret.name@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

        /** @var Email $notice */
        $notice = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->first(fn ($message) => $message->getSubject() === 'The email address on your account was changed');

        $this->assertNotNull($notice, 'the notice was sent: '.json_encode(collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($s) => $s->getOriginalMessage()->getSubject())->all()));
        $this->assertSame(['original@example.com'], array_map(fn ($address) => $address->getAddress(), $notice->getTo()), 'to the address it used to have, and to nobody else');

        $text = (string) $notice->getTextBody();

        $this->assertStringContainsString('s***@example.org', $text);
        $this->assertStringNotContainsString('secret.name', $text);
        $this->assertStringContainsString('127.0.0.1', $text);
        $this->assertStringContainsString(route('password.edit'), $text, 'it leads to where the password is changed');
    }

    #[Test]
    public function a_notice_that_cannot_be_sent_does_not_undo_the_change(): void
    {
        Exceptions::fake();
        // Only the notice cannot be queued: the verification email, sent in the same request, goes through.
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                if ($notification instanceof EmailAddressChanged) {
                    throw new RuntimeException('The mail server is down.');
                }
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                $this->send($notifiables, $notification);
            }
        });
        $user = $this->member();

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'verification-link-sent');

        $this->assertSame('new@example.com', $user->fresh()->email, 'the change is made');
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The mail server is down.');
    }

    #[Test]
    public function a_change_that_is_refused_tells_nobody(): void
    {
        Notification::fake();
        $user = $this->member();

        $this->save($user, ['email' => 'not-an-address', 'current_password' => 'password'])->assertSessionHasErrors('email');

        Notification::assertNotSentTo($user, EmailAddressChanged::class);
    }

    // --- Guessing ---------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function guessing_the_password_from_here_stops_after_five_a_minute(): void
    {
        $user = $this->member();

        foreach (range(1, 5) as $try) {
            $this->save($user, ['email' => 'new@example.com', 'current_password' => 'guess-'.$try])->assertSessionHasErrors('current_password');
        }

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])->assertTooManyRequests();

        $this->assertSame('original@example.com', $user->fresh()->email);
    }

    #[Test]
    public function the_guesses_are_shared_with_the_other_places_that_check_the_password(): void
    {
        $user = $this->member();

        foreach (range(1, 5) as $try) {
            $this->save($user, ['email' => 'new@example.com', 'current_password' => 'guess-'.$try]);
        }

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-password-1',
            'password_confirmation' => 'a-new-password-1',
        ])->assertTooManyRequests();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertTooManyRequests();
        $this->assertNotNull($user->fresh(), 'the account was not deleted');
    }

    #[Test]
    public function saving_the_profile_without_a_new_address_is_not_counted_as_a_guess(): void
    {
        $user = $this->member();

        foreach (range(1, 8) as $save) {
            $this->save($user, ['nickname' => 'Name '.$save])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        }

        $this->save($user, ['email' => 'new@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    #[Test]
    public function the_account_can_still_be_deleted_with_the_password(): void
    {
        $user = $this->member();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

        $this->assertNull($user->fresh());
    }

    // --- The rule itself --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function what_counts_as_another_address(): void
    {
        $user = User::factory()->make(['email' => 'original@example.com']);

        $this->assertFalse(EmailChange::isChange($user, 'original@example.com'));
        $this->assertFalse(EmailChange::isChange($user, '  Original@Example.com '));
        $this->assertTrue(EmailChange::isChange($user, 'original+1@example.com'));
        $this->assertTrue(EmailChange::isChange($user, ''), 'an empty address is another one (the request is refused for it anyway)');
        $this->assertFalse(EmailChange::isChange($user, null), 'no address in the request is not a change');
        $this->assertFalse(EmailChange::isChange($user, ['original@example.com']), 'nor is something that is not text');
    }

    #[Test]
    public function an_address_is_shown_in_part(): void
    {
        $this->assertSame('a***@example.com', EmailChange::mask('avery@example.com'));
        $this->assertSame('é***@example.com', EmailChange::mask('élodie@example.com'));
        $this->assertSame('***', EmailChange::mask('not an address'));
    }
}
