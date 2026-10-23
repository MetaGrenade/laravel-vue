<?php

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ApiKeyCreated;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Somebody who has an API key made on their account is told by email, whoever made it and however (docs/api-keys.md).
 */
class ApiKeyNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
    }

    private function create(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('settings.api-keys.store'), [
            'name' => 'Deploy script', 'scopes' => ['profile:read'], 'expires_in' => '90', ...$overrides,
        ]);
    }

    private function sentTo(User $user): ApiKeyCreated
    {
        $sent = Notification::sent($user, ApiKeyCreated::class);

        $this->assertCount(1, $sent, 'exactly one email');

        return $sent->first();
    }

    // --- When it is sent ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_made_in_settings_is_told_to_its_owner(): void
    {
        $user = User::factory()->create();

        $this->create($user, ['scopes' => ['profile:read', 'polls:vote']]);

        $notification = $this->sentTo($user);

        $this->assertSame('Deploy script', $notification->name);
        $this->assertSame(ApiKeyCreated::SETTINGS, $notification->how);
        $this->assertSame(['Read your profile', 'Vote in polls'], $notification->scopes);
        $this->assertFalse($notification->fullAccess);
        $this->assertTrue($notification->expiresAt->equalTo(now()->addDays(90)));
        $this->assertSame('127.0.0.1', $notification->ip);
        $this->assertNull($notification->replaced);
    }

    #[Test]
    public function a_key_made_by_signing_in_over_the_api_is_told_to_its_owner(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])->assertCreated();

        $notification = $this->sentTo($user);

        $this->assertSame(ApiKeyCreated::SIGN_IN, $notification->how);
        $this->assertSame('phone', $notification->name);
        $this->assertSame(['Read your profile'], $notification->scopes);
    }

    #[Test]
    public function a_key_with_full_access_says_so(): void
    {
        config(['api.sign_in.scopes' => ['*']]);
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->assertCreated();

        $notification = $this->sentTo($user);

        $this->assertTrue($notification->fullAccess);
        $this->assertSame([], $notification->scopes);
    }

    #[Test]
    public function a_key_staff_make_for_somebody_is_told_to_them_and_not_to_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $owner = User::factory()->create();

        $this->actingAs($admin)->post(route('acp.tokens.store'), [
            'name' => 'For the integration', 'user_id' => $owner->id, 'abilities' => ['*'], 'expires_at' => null,
        ])->assertSessionHasNoErrors();

        $notification = $this->sentTo($owner);

        $this->assertSame(ApiKeyCreated::STAFF, $notification->how);
        $this->assertSame('For the integration', $notification->name);
        $this->assertTrue($notification->fullAccess);
        $this->assertNull($notification->expiresAt);
        Notification::assertNotSentTo($admin, ApiKeyCreated::class);
    }

    #[Test]
    public function nothing_is_sent_when_no_key_is_made(): void
    {
        config(['api.keys.max_active' => 1]);
        $user = User::factory()->create();

        $this->create($user);
        Notification::fake();

        $this->create($user)->assertSessionHasErrors('limit');
        $this->create($user, ['scopes' => []])->assertSessionHasErrors('scopes');

        Notification::assertNothingSent();
    }

    #[Test]
    public function nothing_is_sent_to_somebody_who_may_not_make_keys_yet(): void
    {
        SystemSetting::set('email_verification_required', true);
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();

        Notification::assertNothingSent();
    }

    #[Test]
    public function taking_a_key_away_or_limiting_it_sends_nothing(): void
    {
        $user = User::factory()->create();
        $key = $user->createToken('Quiet', ['profile:read'])->accessToken;

        $this->actingAs($user)->patch(route('settings.api-keys.limits', $key->id), ['hourly_quota' => 10, 'daily_quota' => 100]);
        $this->post(route('settings.api-keys.revoke', $key->id));
        $this->delete(route('settings.api-keys.destroy', $key->id));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_is_sent_by_email_whatever_the_persons_notification_settings_say(): void
    {
        $notification = new ApiKeyCreated('Key', ApiKeyCreated::SETTINGS, [], false, null, null);

        $this->assertSame(['mail'], $notification->via(User::factory()->make()));
        $this->assertSame(['mail' => 'mail'], $notification->viaQueues());
        $this->assertInstanceOf(ShouldQueue::class, $notification);
    }

    #[Test]
    public function a_mail_that_cannot_be_sent_does_not_undo_the_key(): void
    {
        Exceptions::fake();
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('The mail server is down.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('The mail server is down.');
            }
        });
        $user = User::factory()->create();

        $this->create($user)->assertSessionHasNoErrors()->assertSessionHas('plain_text_token');

        $this->assertSame(1, $user->tokens()->count(), 'the key was made, and its secret is in the response');
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The mail server is down.');
    }

    // --- What it says ---------------------------------------------------------------------------------------------------------------------

    private function text(ApiKeyCreated $notification, ?User $to = null): string
    {
        $mail = $notification->toMail($to ?? User::factory()->make(['nickname' => 'Jane']));

        return $mail->subject.' | '.implode(' ', array_merge([$mail->greeting], $mail->introLines, [$mail->actionText, $mail->actionUrl]));
    }

    #[Test]
    public function the_email_says_what_was_made_by_whom_from_where_and_what_it_can_do(): void
    {
        $text = $this->text(new ApiKeyCreated('Deploy script', ApiKeyCreated::SETTINGS, ['Read your profile', 'Vote in polls'], false, now()->addDays(30), '203.0.113.9'));

        $this->assertStringContainsString('A new API key was made on your account', $text);
        $this->assertStringContainsString('Hi Jane!', $text);
        $this->assertStringContainsString('`Deploy script` was made in your account settings', $text);
        $this->assertStringContainsString('from the address `203.0.113.9`', $text);
        $this->assertStringContainsString('It can: Read your profile, Vote in polls.', $text);
        $this->assertStringContainsString('It expires on', $text);
        $this->assertStringContainsString('revoke the key now and change your password', $text);
        $this->assertStringContainsString('Review your API keys '.route('settings.api-keys.index'), $text);
    }

    #[Test]
    public function the_email_says_how_a_key_came_to_be_made(): void
    {
        $text = fn (string $how) => $this->text(new ApiKeyCreated('K', $how, [], false, null, null, 'Old', null));

        $this->assertStringContainsString('by signing in over the API', $text(ApiKeyCreated::SIGN_IN));
        $this->assertStringContainsString('A member of staff made an API key', $text(ApiKeyCreated::STAFF));
        $this->assertStringContainsString('Staff added an API key to your account', $text(ApiKeyCreated::STAFF));
        $this->assertStringContainsString('Your API key `Old` was rotated: a new key called `K` was made to replace it.', $text(ApiKeyCreated::ROTATION));
        $this->assertStringContainsString('An API key on your account was rotated', $text(ApiKeyCreated::ROTATION));
    }

    #[Test]
    public function the_email_says_whether_the_old_key_keeps_working_after_a_rotation(): void
    {
        $until = new ApiKeyCreated('K', ApiKeyCreated::ROTATION, [], false, null, null, 'Old', now()->addHours(24));
        $atOnce = new ApiKeyCreated('K', ApiKeyCreated::ROTATION, [], false, null, null, 'Old', null);

        $this->assertStringContainsString('The old key keeps working until', $this->text($until));
        $this->assertStringContainsString('The old key stopped working at once.', $this->text($atOnce));
        $this->assertStringNotContainsString('old key', $this->text(new ApiKeyCreated('K', ApiKeyCreated::SETTINGS, [], false, null, null)));
    }

    #[Test]
    public function the_email_says_full_access_and_that_a_key_does_not_expire(): void
    {
        $text = $this->text(new ApiKeyCreated('K', ApiKeyCreated::SETTINGS, [], true, null, null));

        $this->assertStringContainsString('It has full access to your account.', $text);
        $this->assertStringContainsString('It does not expire.', $text);
        $this->assertStringNotContainsString('from the address', $text);
    }

    #[Test]
    public function the_email_says_when_a_key_can_do_nothing(): void
    {
        $this->assertStringContainsString('It cannot do anything.', $this->text(new ApiKeyCreated('K', ApiKeyCreated::SETTINGS, [], false, null, null)));
    }

    #[Test]
    public function the_email_shows_times_in_the_persons_own_time_zone(): void
    {
        $user = User::factory()->make(['nickname' => 'Jane', 'timezone' => 'Asia/Tokyo']);
        $notification = new ApiKeyCreated('K', ApiKeyCreated::SETTINGS, [], false, CarbonImmutable::parse('2026-11-01 20:00:00', 'UTC'), null);

        $this->assertStringContainsString('Mon, Nov 2, 2026 5:00 AM', implode(' ', $notification->toMail($user)->introLines), 'Tokyo is nine hours ahead: 20:00 UTC on the 1st is 5:00 the next morning');
    }

    #[Test]
    public function the_email_never_holds_the_secret(): void
    {
        $user = User::factory()->create();

        $secret = $this->create($user)->getSession()->get('plain_text_token');

        $sent = $this->sentTo($user);

        $this->assertStringNotContainsString(explode('|', $secret)[1], $this->text($sent, $user));
        $this->assertStringNotContainsString(explode('|', $secret)[1], serialize($sent));
    }

    #[Test]
    public function a_name_somebody_chose_cannot_become_a_link_or_markup_in_the_email(): void
    {
        $hostile = '[Sign in again](https://evil.test/login) https://evil.test/bare <a href="https://evil.test/tag">tag</a> <script>alert(1)</script>';

        $html = (string) (new ApiKeyCreated($hostile, ApiKeyCreated::STAFF, [], false, null, '203.0.113.9'))
            ->toMail(User::factory()->make(['nickname' => 'Jane']))
            ->render();

        $this->assertStringNotContainsString('href="https://evil.test', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<a href="https://evil.test/tag">', $html);
        $this->assertStringContainsString('Sign in again', $html, 'the name is still there, as text');
    }

    #[Test]
    public function a_name_with_backticks_or_line_breaks_stays_inside_its_quotes(): void
    {
        $this->assertSame("`it's a name with spaces`", ApiKeyCreated::literal("it`s\n a  name\twith spaces "));
    }
}
