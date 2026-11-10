<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TwoFactorWasReset;
use App\Support\Security\SignInMethods;
use App\Support\Security\TwoFactorAuthenticator;
use App\Support\Security\TwoFactorReset;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Staff switching two-factor off for a member who lost their authenticator app and recovery codes. Without it such a member has no way
 * back from the site. It takes a protection away on somebody's word, so it has its own permission, says why, is in the audit log, and
 * emails the member. docs/two-factor.md, "When a member is locked out".
 *
 * (The admin role passes every permission check, so most of these use the editor role with the permission given.)
 */
class UserTwoFactorResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'editor', 'moderator'] as $role) {
            Role::findOrCreate($role);
        }

        foreach (['users.acp.view', 'users.acp.edit', 'users.acp.reset-two-factor'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    private function staff(string $role = 'editor', string ...$permissions): User
    {
        $staff = User::factory()->create();
        $staff->assignRole($role);
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function resetter(): User
    {
        return $this->staff('editor', 'users.acp.edit', 'users.acp.reset-two-factor');
    }

    private function administrator(): User
    {
        return $this->staff('admin');
    }

    /**
     * A member who has two-factor on, with a set of recovery codes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function lockedOut(array $attributes = []): User
    {
        $member = User::factory()->create($attributes);

        $member->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['AAAA-1111-ZZZZ', 'BBBB-2222-YYYY']),
        ])->save();

        return $member;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reset(User $staff, User $member, array $data = ['reason' => 'Ticket 4411: lost the phone']): TestResponse
    {
        return $this->actingAs($staff)->delete(route('acp.users.two-factor.destroy', $member), $data);
    }

    private function assertStillOn(User $member): void
    {
        $member->refresh();

        $this->assertNotNull($member->two_factor_secret, 'the secret is still there');
        $this->assertNotNull($member->two_factor_confirmed_at, 'and still asked for at sign-in');
        $this->assertNotNull($member->two_factor_recovery_codes, 'with its codes');
    }

    private function assertSwitchedOff(User $member): void
    {
        $member->refresh();

        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_confirmed_at);
        $this->assertNull($member->two_factor_recovery_codes);
    }

    // --- Doing it -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function staff_with_the_permission_switch_it_off_and_the_member_is_emailed(): void
    {
        Notification::fake();
        $member = $this->lockedOut();

        $this->reset($this->resetter(), $member)
            ->assertRedirect(route('acp.users.edit', $member))
            ->assertSessionHas('success', 'Two-factor was turned off for this account, and the member was emailed.');

        $this->assertSwitchedOff($member);
        Notification::assertSentTo($member, TwoFactorWasReset::class);
    }

    #[Test]
    public function the_member_signs_in_with_the_password_alone_afterwards(): void
    {
        $member = $this->lockedOut(['email' => 'locked@example.com']);

        $this->reset($this->resetter(), $member)->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        $this->post(route('login'), ['email' => 'locked@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
    }

    #[Test]
    public function a_setup_that_was_started_and_never_confirmed_is_cleared_too(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret())])->save();

        $this->reset($this->resetter(), $member)->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->two_factor_secret);
        $this->assertSame('started', AuditLog::query()->sole()->meta['was']);
    }

    #[Test]
    public function an_administrator_can_without_being_given_the_permission_by_name(): void
    {
        // The admin role passes every permission check.
        $member = $this->lockedOut();

        $this->reset($this->administrator(), $member)->assertSessionHasNoErrors();

        $this->assertSwitchedOff($member);
    }

    // --- Who may --------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function staff_without_the_permission_cannot(): void
    {
        Notification::fake();
        $member = $this->lockedOut();

        $this->reset($this->staff('editor', 'users.acp.edit'), $member)->assertForbidden();

        $this->assertStillOn($member);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_moderator_is_not_given_it_by_default(): void
    {
        // setUp made the permission so the other tests can hand it out; here the seeder has to make it, or a site never gets the permission.
        Permission::query()->where('name', 'users.acp.reset-two-factor')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::findByName('users.acp.reset-two-factor')->roles->pluck('name')->contains('admin'));
        $this->assertFalse(Permission::findByName('users.acp.reset-two-factor')->roles->pluck('name')->contains('moderator'));
        $this->assertFalse(Permission::findByName('users.acp.reset-two-factor')->roles->pluck('name')->contains('editor'));
    }

    #[Test]
    public function a_customer_cannot(): void
    {
        $member = $this->lockedOut();

        $this->reset(User::factory()->create(), $member)->assertStatus(403);

        $this->assertStillOn($member);
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $member = $this->lockedOut();

        $this->delete(route('acp.users.two-factor.destroy', $member), ['reason' => 'Ticket 4411: lost the phone'])->assertRedirect(route('login'));

        $this->assertStillOn($member);
    }

    #[Test]
    public function the_route_is_guarded_by_its_own_permission(): void
    {
        $this->assertContains('can:users.acp.reset-two-factor', app('router')->getRoutes()->getByName('acp.users.two-factor.destroy')->gatherMiddleware());
    }

    #[Test]
    public function nobody_can_do_it_to_their_own_account_here(): void
    {
        // Settings → Security is the way, and it asks for the password; here it would be a way round that for whoever holds the session.
        $administrator = $this->administrator();
        $administrator->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->reset($administrator, $administrator)
            ->assertRedirect()
            ->assertSessionHasErrors('two_factor');

        $this->assertNotNull($administrator->fresh()->two_factor_confirmed_at);
        $this->assertStringContainsString('your own', session('errors')->first('two_factor'));
    }

    #[Test]
    public function only_an_administrator_can_do_it_to_a_member_of_staff(): void
    {
        Notification::fake();
        $moderator = $this->lockedOut();
        $moderator->assignRole('moderator');

        $this->reset($this->resetter(), $moderator)
            ->assertRedirect()
            ->assertSessionHasErrors('two_factor');

        $this->assertStillOn($moderator);
        $this->assertSame('Only an administrator can reset two-factor for a member of staff.', session('errors')->first('two_factor'));
        Notification::assertNothingSent();

        $this->reset($this->administrator(), $moderator)->assertSessionHasNoErrors();

        $this->assertSwitchedOff($moderator);
    }

    #[Test]
    public function an_account_with_nothing_set_up_is_refused_and_nobody_is_emailed(): void
    {
        Notification::fake();
        $member = User::factory()->create();

        $this->reset($this->resetter(), $member)
            ->assertRedirect()
            ->assertSessionHasErrors('two_factor');

        $this->assertSame('Two-factor is not set up for this account.', session('errors')->first('two_factor'));
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->where('event', 'users.two-factor.reset')->where('outcome', AuditLog::SUCCEEDED)->count(), 'and nothing was done to record');
    }

    // --- Why ------------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_reason_is_required_and_nothing_changes_without_it(): void
    {
        Notification::fake();
        $member = $this->lockedOut();

        $this->reset($this->resetter(), $member, [])->assertSessionHasErrors('reason');
        $this->reset($this->resetter(), $member, ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->reset($this->resetter(), $member, ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->reset($this->resetter(), $member, ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');

        $this->assertStillOn($member);
        Notification::assertNothingSent();
    }

    // --- The audit log --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_audit_log_says_who_did_it_to_whom_and_why(): void
    {
        $staff = $this->resetter();
        $member = $this->lockedOut();

        $this->reset($staff, $member)->assertSessionHasNoErrors();

        $entry = AuditLog::query()->sole();

        $this->assertSame('users.two-factor.reset', $entry->event);
        $this->assertSame(AuditLog::SUCCEEDED, $entry->outcome);
        $this->assertSame($staff->id, (int) $entry->actor_id);
        $this->assertSame(User::class, $entry->subject_type);
        $this->assertSame($member->id, (int) $entry->subject_id);
        $this->assertSame('Ticket 4411: lost the phone', $entry->meta['reason']);
        $this->assertSame('confirmed', $entry->meta['was']);
    }

    #[Test]
    public function the_audit_log_keeps_none_of_the_secrets(): void
    {
        $member = $this->lockedOut();
        $secret = $member->two_factor_secret;
        $codes = $member->two_factor_recovery_codes;

        $this->reset($this->resetter(), $member)->assertSessionHasNoErrors();

        $stored = json_encode(AuditLog::query()->sole()->only(['changes', 'meta']));

        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString($codes, $stored);
        $this->assertStringContainsString('two_factor_confirmed_at', $stored, 'what changed is still said');
    }

    #[Test]
    public function a_refusal_is_in_the_log_as_something_that_did_not_work(): void
    {
        $this->reset($this->resetter(), User::factory()->create())->assertSessionHasErrors('two_factor');

        $entry = AuditLog::query()->where('event', 'users.two-factor.reset')->sole();

        $this->assertSame(AuditLog::FAILED, $entry->outcome);
        $this->assertSame('Ticket 4411: lost the phone', $entry->meta['reason'], 'with the reason that was given');
    }

    // --- Telling the member ---------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_email_reaches_the_member_and_says_what_happened_without_naming_who_or_why(): void
    {
        $staff = $this->resetter();
        $member = $this->lockedOut(['email' => 'locked@example.com']);

        $this->reset($staff, $member, ['reason' => 'Ticket 4411: a secret reason'])->assertSessionHasNoErrors();

        /** @var Email|null $mail */
        $mail = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->first(fn ($message) => $message->getSubject() === 'Two-factor authentication was turned off on your account');

        $this->assertNotNull($mail, 'it was sent');
        $this->assertSame(['locked@example.com'], array_map(fn ($address) => $address->getAddress(), $mail->getTo()));

        $text = (string) $mail->getTextBody();

        $this->assertStringContainsString('turned two-factor authentication off', $text);
        $this->assertStringNotContainsString(':date', $text, 'the time it happened is filled in');
        $this->assertStringContainsString(now()->format('Y'), $text);
        $this->assertStringContainsString(route('security.edit'), $text, 'it leads to the security settings');
        $this->assertStringNotContainsString('4411', $text, 'the reason is for the audit log, not for the member');
        $this->assertStringNotContainsString($staff->nickname, $text, 'nor who did it');
    }

    #[Test]
    public function a_member_whose_address_the_site_made_up_is_not_emailed_and_the_page_says_so(): void
    {
        Notification::fake();
        $member = $this->lockedOut(['email' => 'steam_1@'.SignInMethods::PLACEHOLDER_DOMAIN]);

        $this->reset($this->resetter(), $member)
            ->assertSessionHas('success', 'Two-factor was turned off for this account. The member was not emailed: their address cannot receive mail.');

        $this->assertSwitchedOff($member);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_notice_that_cannot_be_sent_does_not_undo_the_reset(): void
    {
        Exceptions::fake();
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                if ($notification instanceof TwoFactorWasReset) {
                    throw new RuntimeException('The mail server is down.');
                }
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                $this->send($notifiables, $notification);
            }
        });
        $member = $this->lockedOut();

        $this->reset($this->resetter(), $member)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('acp.users.edit', $member))
            ->assertSessionHas('success', 'Two-factor was turned off for this account. The member was not emailed: their address cannot receive mail.');

        $this->assertSwitchedOff($member);
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The mail server is down.');
    }

    // --- The page -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_edit_page_says_what_the_account_has_and_whether_the_staff_member_may(): void
    {
        $staff = $this->resetter();

        $this->actingAs($staff)->get(route('acp.users.edit', $this->lockedOut()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.can', true)
                ->where('twoFactor.state', 'on')
                ->where('twoFactor.refusal', null)
                ->where('twoFactor.canEmail', true));

        $pending = User::factory()->create();
        $pending->forceFill(['two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret())])->save();

        $this->get(route('acp.users.edit', $pending))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactor.state', 'pending')->where('twoFactor.refusal', null));

        $this->get(route('acp.users.edit', User::factory()->create()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.state', 'off')
                ->where('twoFactor.refusal', 'Two-factor is not set up for this account.'));

        $this->get(route('acp.users.edit', $this->lockedOut(['email' => 'steam_1@'.SignInMethods::PLACEHOLDER_DOMAIN])))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactor.canEmail', false));
    }

    #[Test]
    public function the_edit_page_does_not_offer_it_without_the_permission(): void
    {
        $this->actingAs($this->staff('editor', 'users.acp.edit'))->get(route('acp.users.edit', $this->lockedOut()))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactor.can', false));
    }

    #[Test]
    public function the_edit_page_carries_none_of_the_secrets(): void
    {
        $member = $this->lockedOut();

        $response = $this->actingAs($this->resetter())->get(route('acp.users.edit', $member));

        $this->assertStringNotContainsString($member->two_factor_secret, $response->getContent());
        $this->assertStringNotContainsString($member->two_factor_recovery_codes, $response->getContent());
    }

    #[Test]
    public function the_refusal_the_page_shows_is_the_one_the_server_gives(): void
    {
        $reset = new TwoFactorReset;
        $staff = $this->resetter();

        $this->assertSame('Two-factor is not set up for this account.', $reset->refusal($staff, User::factory()->create()));
        $this->assertNull($reset->refusal($staff, $this->lockedOut()));
        $this->assertStringContainsString('your own', (string) $reset->refusal($staff, $staff));
    }

    #[Test]
    public function called_from_code_with_a_person_the_log_names_that_person(): void
    {
        // Outside an admin request nothing has opened an entry, so the service writes it, and it has to say who did it.
        Notification::fake();
        $member = $this->lockedOut();
        $staff = $this->resetter();

        app(TwoFactorReset::class)->reset($member, $staff, 'Ticket 4411: lost the phone');

        $entry = AuditLog::query()->sole();
        $this->assertSame('users.two-factor.reset', $entry->event);
        $this->assertSame($staff->id, (int) $entry->actor_id);
        $this->assertSame($member->id, (int) $entry->subject_id);
        $this->assertSame('Ticket 4411: lost the phone', $entry->meta['reason']);
    }
}
