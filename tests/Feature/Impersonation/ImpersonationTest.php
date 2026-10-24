<?php

namespace Tests\Feature\Impersonation;

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventBannedUser;
use App\Http\Middleware\UpdateLastActivity;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Impersonation\Impersonation;
use App\Support\Security\TwoFactorAuthenticator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff seeing the site as one customer sees it, read only (docs/impersonation.md).
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['nickname' => 'Ada Admin', 'email' => 'ada@example.com']);
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }

    /**
     * The first customer a test makes is Jane; any others are somebody else (nickname and email are unique).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): User
    {
        $jane = ! User::query()->where('email', 'jane@example.com')->exists();

        return User::factory()->create($attributes + [
            'nickname' => $jane ? 'Jane Customer' : 'Customer '.Str::random(8),
            'email' => $jane ? 'jane@example.com' : Str::lower(Str::random(8)).'@example.com',
        ]);
    }

    /**
     * Sign the staff member in and put them in the middle of a view as this customer.
     *
     * @param  array<string, mixed>  $state
     */
    private function viewing(User $admin, User $customer, array $state = []): static
    {
        $this->actingAs($admin);

        return $this->withSession([Impersonation::SESSION_KEY => $state + [
            'admin_id' => $admin->id,
            'user_id' => $customer->id,
            'reason' => 'Ticket 4411: cannot see their invoice',
            'started_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
            'views' => 0,
        ]]);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function entries(?string $event = null)
    {
        return AuditLog::query()->when($event, fn ($query) => $query->where('event', $event))->orderBy('id')->get();
    }

    // --- Starting -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_admin_starts_a_view_with_a_reason_and_it_is_written_to_the_audit_log(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->post(route('acp.users.impersonate', $customer), ['reason' => 'Ticket 4411: cannot see their invoice'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(Impersonation::SESSION_KEY.'.user_id', $customer->id)
            ->assertSessionHas(Impersonation::SESSION_KEY.'.admin_id', $admin->id)
            ->assertSessionHas(Impersonation::SESSION_KEY.'.reason', 'Ticket 4411: cannot see their invoice')
            ->assertSessionHas(Impersonation::SESSION_KEY.'.expires_at', now()->addMinutes(30)->getTimestamp());

        $entry = $this->entries()->sole();

        $this->assertSame('impersonation.started', $entry->event);
        $this->assertSame(AuditLog::SUCCEEDED, $entry->outcome);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($customer->id, $entry->subject_id);
        $this->assertSame('Ticket 4411: cannot see their invoice', $entry->meta['reason']);
        $this->assertSame(30, $entry->meta['minutes']);
        $this->assertSame([$customer->id], $entry->accounts()->pluck('owner_id')->map(fn ($id) => (int) $id)->all(), 'in the history of the account that was looked at');
    }

    #[Test]
    public function how_long_a_view_lasts_is_set_in_the_configuration(): void
    {
        config(['impersonation.minutes' => 5]);
        $customer = $this->customer();

        $this->actingAs($this->admin())->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund'])
            ->assertSessionHas(Impersonation::SESSION_KEY.'.expires_at', now()->addMinutes(5)->getTimestamp());
    }

    #[Test]
    public function a_reason_is_needed_and_without_one_nothing_starts(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())->post(route('acp.users.impersonate', $customer), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.impersonate', $customer), ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.impersonate', $customer), ['reason' => '       '])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.impersonate', $customer), ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');

        $this->assertNull(session(Impersonation::SESSION_KEY));

        // Each try is on record as one that did not work (under the request's own name: the request never reached the code that renames it).
        $this->assertCount(4, $this->entries());
        $this->assertSame([AuditLog::FAILED], $this->entries()->pluck('outcome')->unique()->all());
        $this->assertSame(['users.impersonate'], $this->entries()->pluck('event')->unique()->all());
    }

    #[Test]
    public function staff_without_the_permission_cannot_start_one(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('editor'));
        $customer = $this->customer();

        $this->actingAs($editor)->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund'])->assertForbidden();

        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->assertSame(AuditLog::DENIED, $this->entries()->sole()->outcome, 'a refusal is recorded');
    }

    #[Test]
    public function staff_who_are_given_the_permission_can(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('editor'));
        $editor->givePermissionTo(Permission::findOrCreate('users.acp.impersonate'));
        $customer = $this->customer();

        $this->actingAs($editor)->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(Impersonation::SESSION_KEY.'.user_id', $customer->id);
    }

    #[Test]
    public function nobody_signed_out_and_no_customer_can_start_one(): void
    {
        $customer = $this->customer();

        $this->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund'])->assertRedirect(route('login'));

        $this->actingAs($this->customer(['email' => 'other@example.com']))
            ->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund'])
            ->assertForbidden();

        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor'], 'moderator' => ['moderator']];
    }

    #[Test]
    #[DataProvider('staffRoles')]
    public function staff_cannot_be_viewed_as(string $role): void
    {
        $colleague = $this->customer(['email' => 'colleague@example.com']);
        $colleague->assignRole(Role::findOrCreate($role));

        $this->actingAs($this->admin())->post(route('acp.users.impersonate', $colleague), ['reason' => 'Looking into a refund'])
            ->assertSessionHasErrors(['impersonate' => 'Staff accounts cannot be viewed as. Only customers can.']);

        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    #[Test]
    public function a_banned_account_and_oneself_cannot_be_viewed_as(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.users.impersonate', $this->customer(['is_banned' => true])), ['reason' => 'Looking into a refund'])
            ->assertSessionHasErrors(['impersonate' => 'This account is banned, so it cannot be viewed as.']);

        $this->post(route('acp.users.impersonate', $admin), ['reason' => 'Looking into a refund'])
            ->assertSessionHasErrors(['impersonate' => 'You cannot view as yourself.']);

        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    #[Test]
    public function starting_a_view_tells_the_customer_nothing_and_signs_nobody_in(): void
    {
        Notification::fake();
        Mail::fake();
        Event::fake([Login::class]);

        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund']);
        $this->viewing($admin, $customer)->get(route('dashboard'))->assertOk();

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Event::assertNotDispatched(Login::class);
    }

    // --- Viewing --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function while_viewing_every_request_is_the_customers_and_the_page_says_so(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $customer->id)
            ->where('auth.user.nickname', 'Jane Customer')
            ->where('impersonation.user.id', $customer->id)
            ->where('impersonation.user.email', 'jane@example.com')
            ->where('impersonation.by', 'Ada Admin')
            ->where('impersonation.reason', 'Ticket 4411: cannot see their invoice')
            ->where('impersonation.stop_url', '/impersonation/stop')
            ->where('impersonation.expires_at', now()->addMinutes(30)->toIso8601String())
            ->where('ziggy.group', 'public'));
    }

    #[Test]
    public function without_a_view_nothing_changes_and_there_is_no_bar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $admin->id)
            ->where('impersonation', null)
            ->where('ziggy.group', 'staff'));

        $this->assertSame([], $this->entries()->all(), 'ordinary requests are not recorded');
    }

    #[Test]
    public function the_staff_member_is_put_back_before_the_session_is_saved(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertOk();

        $this->assertTrue(Auth::guard('web')->user()->is($admin), 'the sign-in is still the staff members');
    }

    #[Test]
    public function the_session_stays_the_staff_members_in_the_database(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();

        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertOk();

        // The customer's list of signed-in devices is read from this table: the staff session must not turn up in it.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $customer->id)->count(), 'not a device of the customer');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());
    }

    #[Test]
    public function the_staff_members_powers_are_not_available_while_viewing(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get(route('acp.users.index'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'That page is not available while you are viewing as somebody else.');

        $this->assertSame(['impersonation.blocked'], $this->entries()->pluck('event')->all());
    }

    #[Test]
    public function nothing_can_be_changed_while_viewing(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)
            ->withHeader('Referer', route('profile.edit'))
            ->patch(route('profile.update'), ['nickname' => 'Hacked', 'email' => 'hacked@example.com'])
            ->assertStatus(303)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'This is a read-only view: nothing can be changed while you are viewing as Jane Customer.')
            ->assertSessionHasErrors(['impersonation' => 'This is a read-only view: nothing can be changed while you are viewing as Jane Customer.']);

        $this->assertSame('Jane Customer', $customer->fresh()->nickname);
        $this->assertSame('jane@example.com', $customer->fresh()->email);

        $entry = $this->entries('impersonation.blocked')->sole();
        $this->assertSame(AuditLog::DENIED, $entry->outcome);
        $this->assertSame('PATCH', $entry->meta['method']);
        $this->assertSame('/settings/profile', $entry->meta['path']);
        $this->assertSame(403, $entry->meta['status']);
    }

    #[Test]
    public function an_account_cannot_be_deleted_or_a_password_changed_while_viewing(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $password = $customer->password;

        $this->viewing($admin, $customer)->delete(route('profile.destroy'), ['password' => 'password'])->assertStatus(303);
        $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertStatus(303);

        $this->assertModelExists($customer);
        $this->assertSame($password, $customer->fresh()->password);
    }

    #[Test]
    public function a_client_that_wants_json_is_told_so(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->patchJson(route('profile.update'), ['nickname' => 'Hacked'])
            ->assertForbidden()
            ->assertJson(['message' => 'This is a read-only view: nothing can be changed while you are viewing as Jane Customer.']);

        $this->assertSame('Jane Customer', $customer->fresh()->nickname);
    }

    #[Test]
    public function a_writing_request_to_the_admin_area_is_refused_and_not_run(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $other = $this->customer(['email' => 'other@example.com']);

        $this->viewing($admin, $customer)->put(route('acp.users.ban', $other))->assertStatus(303);

        $this->assertFalse((bool) $other->fresh()->is_banned);
        $this->assertSame(['impersonation.blocked'], $this->entries()->pluck('event')->all(), 'one entry, not also a change in the admin area');
    }

    #[Test]
    public function what_a_page_asks_for_by_itself_is_turned_away_and_not_written_down(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        // Each page asks to listen for live updates as it opens. The person did not do that, so it is not an entry.
        $this->viewing($admin, $customer)
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$customer->id])
            ->assertForbidden()
            ->assertJson(['message' => 'Live updates are not available while you are viewing as somebody.']);

        $this->assertSame([], $this->entries()->all());
    }

    #[Test]
    public function the_api_is_not_available_with_the_staff_members_session_while_viewing(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)
            ->withHeader('Referer', config('app.url'))
            ->getJson('/api/v1/profile')
            ->assertForbidden()
            ->assertJson(['message' => 'The API is not available while you are viewing as somebody.']);

        $this->assertSame(['impersonation.blocked'], $this->entries()->pluck('event')->all());
    }

    #[Test]
    public function what_a_page_does_as_it_is_looked_at_is_not_kept(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertOk();

        $this->assertNull($customer->fresh()->last_activity_at, 'looking is not being active');

        // And the same page, for the customer, does record it (so the check above can tell).
        Auth::guard('web')->forgetUser();
        $this->actingAs($customer)->get(route('dashboard'))->assertOk();
        $this->assertNotNull($customer->fresh()->last_activity_at);
    }

    #[Test]
    public function a_page_that_writes_and_leaves_a_transaction_open_is_put_back(): void
    {
        $this->viewableTestRoute('test.writes', function (Request $request) {
            User::factory()->create(['nickname' => 'Made by a GET']);
            DB::beginTransaction();
            User::factory()->create(['nickname' => 'Made in a transaction nobody finished', 'email' => 'unfinished@example.com']);

            return response('seen as '.$request->user()->nickname);
        });

        $admin = $this->admin();
        $customer = $this->customer();
        $users = User::query()->count();
        $level = DB::transactionLevel();

        $this->viewing($admin, $customer)->get('/__test/writes')->assertOk()->assertSee('seen as Jane Customer');

        $this->assertSame($users, User::query()->count(), 'neither was kept');
        $this->assertSame($level, DB::transactionLevel(), 'and nothing is left open');
        $this->assertSame('impersonation.viewed', $this->entries()->sole()->event, 'the page that was seen is still on record');
    }

    #[Test]
    public function a_page_that_fails_is_put_back_too(): void
    {
        $this->viewableTestRoute('test.fails', function () {
            User::factory()->create(['nickname' => 'Made before the failure']);

            throw new RuntimeException('boom');
        });

        $admin = $this->admin();
        $customer = $this->customer();
        $users = User::query()->count();
        $level = DB::transactionLevel();

        Exceptions::fake();
        $this->viewing($admin, $customer)->get('/__test/fails')->assertStatus(500);

        $this->assertSame($users, User::query()->count());
        $this->assertSame($level, DB::transactionLevel());
        $this->assertTrue(Auth::guard('web')->user()->is($admin), 'and it is still the staff member who is signed in');
    }

    #[Test]
    public function the_security_page_does_not_hand_over_recovery_codes(): void
    {
        $customer = $this->customer();
        $customer->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['AAAA-1111', 'BBBB-2222']),
        ])->save();

        // The control: this page does send them to the customer, which is why a view must not.
        $this->actingAs($customer)->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', ['AAAA-1111', 'BBBB-2222']));

        Auth::guard('web')->forgetUser();

        $response = $this->viewing($this->admin(), $customer)->get(route('security.edit'));

        $response->assertRedirect(route('dashboard'))->assertSessionHas('error');
        $this->assertStringNotContainsString('AAAA-1111', $response->getContent());
        $this->assertSame(AuditLog::DENIED, $this->entries('impersonation.blocked')->sole()->outcome);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pagesKeptFromView(): array
    {
        return [
            'security settings' => ['security.edit'],
            'the checkout' => ['shop.checkout'],
            'the sign-in page' => ['login'],
            'a social sign-in' => ['oauth.redirect'],
        ];
    }

    #[Test]
    #[DataProvider('pagesKeptFromView')]
    public function the_pages_kept_from_view_are_refused(string $name): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $url = $name === 'oauth.redirect' ? route($name, 'google') : route($name);

        $this->viewing($admin, $customer)->get($url)->assertRedirect(route('dashboard'))->assertSessionHas('error');

        $this->assertSame([$name], $this->entries('impersonation.blocked')->map(fn (AuditLog $entry) => $entry->meta['route'])->all());
    }

    // --- Ending ---------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function stopping_ends_the_view_and_goes_back_to_the_account(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer, ['views' => 3, 'started_at' => now()->subMinutes(7)->getTimestamp()])
            ->post(route('impersonation.stop'))
            ->assertRedirect(route('acp.users.edit', $customer))
            ->assertSessionMissing(Impersonation::SESSION_KEY)
            ->assertSessionHas('info', 'You have stopped viewing as Jane Customer.');

        $entry = $this->entries()->sole();
        $this->assertSame('impersonation.stopped', $entry->event);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('you', $entry->meta['ended_by']);
        $this->assertSame(3, $entry->meta['pages']);
        $this->assertSame(420, $entry->meta['seconds']);
        $this->assertSame('Ticket 4411: cannot see their invoice', $entry->meta['reason']);
    }

    #[Test]
    public function after_stopping_the_next_request_is_the_staff_members_again(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->post(route('impersonation.stop'));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $admin->id)->where('impersonation', null));
    }

    #[Test]
    public function stopping_with_no_view_does_nothing(): void
    {
        $this->actingAs($this->customer())->post(route('impersonation.stop'))->assertRedirect(route('dashboard'));

        $this->assertSame([], $this->entries()->all());

        Auth::guard('web')->forgetUser();
        $this->post(route('impersonation.stop'))->assertRedirect(route('login'));
    }

    #[Test]
    public function signing_out_while_viewing_stops_the_view_and_keeps_the_staff_member_signed_in(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->post(route('logout'))
            ->assertRedirect(route('acp.users.edit', $customer))
            ->assertSessionMissing(Impersonation::SESSION_KEY);

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('logout', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
    }

    #[Test]
    public function a_view_ends_by_itself_when_the_time_is_up(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->travel(31)->minutes();

        $this->get(route('dashboard'))
            ->assertRedirect(route('acp.users.edit', $customer))
            ->assertSessionMissing(Impersonation::SESSION_KEY)
            ->assertSessionHas('info', 'Your view ended after 30 minutes.');

        $this->assertSame('expired', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
        $this->assertSame([], $this->entries('impersonation.viewed')->all(), 'the page was not shown');
    }

    #[Test]
    public function a_view_is_still_on_a_second_before_the_time_is_up(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer, ['expires_at' => now()->addSecond()->getTimestamp()])->get(route('dashboard'))->assertOk();

        $this->travel(1)->seconds();
        $this->get(route('dashboard'))->assertRedirect();
    }

    #[Test]
    public function a_view_ends_when_the_account_stops_being_one_that_can_be_viewed_as(): void
    {
        $admin = $this->admin();
        $banned = $this->customer();
        $promoted = $this->customer(['email' => 'promoted@example.com']);

        $banned->forceFill(['is_banned' => true])->save();
        $this->viewing($admin, $banned)->get(route('dashboard'))->assertRedirect(route('acp.users.edit', $banned))->assertSessionMissing(Impersonation::SESSION_KEY);
        $this->assertTrue(Auth::guard('web')->user()->is($admin), 'the ban did not sign the staff member out');

        $promoted->assignRole(Role::findOrCreate('moderator'));
        $this->viewing($admin, $promoted)->get(route('dashboard'))->assertRedirect(route('acp.users.edit', $promoted))->assertSessionMissing(Impersonation::SESSION_KEY);

        $this->assertSame(['account', 'account'], $this->entries('impersonation.stopped')->map(fn (AuditLog $entry) => $entry->meta['ended_by'])->all());
    }

    #[Test]
    public function a_view_of_an_account_that_has_gone_ends(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $customer->delete();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertRedirect(route('acp.users.index'));

        $this->assertSame('account', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
    }

    #[Test]
    public function a_view_ends_when_the_staff_member_loses_the_right_to_one(): void
    {
        $editor = User::factory()->create(['nickname' => 'Ed Editor', 'email' => 'ed@example.com']);
        $editor->assignRole(Role::findOrCreate('editor'));
        $customer = $this->customer();

        // Never given it: the view cannot have been theirs.
        $this->viewing($editor, $customer)->get(route('dashboard'))->assertRedirect(route('dashboard'))->assertSessionMissing(Impersonation::SESSION_KEY);

        $this->assertSame('permission', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
    }

    #[Test]
    public function a_view_ends_when_the_staff_member_is_banned(): void
    {
        // The ban check looks at who is signed in, which during a view is the customer, so it is for the view to notice.
        $admin = $this->admin();
        $customer = $this->customer();
        $admin->forceFill(['is_banned' => true])->save();

        $this->viewing($admin, $customer)->get(route('dashboard'))->assertRedirect(route('dashboard'))->assertSessionMissing(Impersonation::SESSION_KEY);

        $this->assertSame('permission', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
    }

    #[Test]
    public function a_view_belongs_to_the_sign_in_that_began_it(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $somebodyElse = $this->customer(['email' => 'else@example.com', 'nickname' => 'Somebody Else']);

        $this->viewing($admin, $customer);
        $this->actingAs($somebodyElse)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $somebodyElse->id)
            ->where('impersonation', null));

        $this->assertSame([], $this->entries()->all(), 'and it is not recorded as anything');
    }

    #[Test]
    public function a_json_request_that_finds_the_view_over_is_told_so(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer, ['expires_at' => now()->subMinute()->getTimestamp()])
            ->getJson(route('dashboard'))
            ->assertForbidden()
            ->assertJson(['message' => 'Your view ended after 30 minutes.']);

        $this->assertSame('expired', $this->entries('impersonation.stopped')->sole()->meta['ended_by']);
    }

    #[Test]
    public function something_in_the_session_that_is_not_a_view_is_not_one(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        foreach ([
            'text' => 'impersonating',
            'no end' => ['admin_id' => $admin->id, 'user_id' => $customer->id, 'started_at' => 1],
            'an id that is text' => ['admin_id' => (string) $admin->id, 'user_id' => $customer->id, 'started_at' => 1, 'expires_at' => PHP_INT_MAX],
        ] as $name => $state) {
            $this->actingAs($admin)->withSession([Impersonation::SESSION_KEY => $state])->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $admin->id)->where('impersonation', null));

            $this->assertSame([], $this->entries()->all(), $name);
        }
    }

    #[Test]
    public function an_address_that_is_not_there_is_not_there_while_viewing_either(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->get('/no-such-page')->assertNotFound();

        $this->assertSame([], $this->entries()->all());
    }

    #[Test]
    public function a_head_request_is_not_a_page_opened(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->call('HEAD', route('dashboard'));

        $this->assertSame([], $this->entries()->all());
    }

    #[Test]
    public function nobody_starts_a_view_while_already_in_one(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));

        $impersonation = app(Impersonation::class);
        $this->assertNull($impersonation->refusal($admin, $customer, $request));

        $impersonation->start($request, $admin, $customer, 'Looking into a refund');

        $this->assertSame('You are already viewing as somebody. Stop that first.', $impersonation->refusal($admin, $customer, $request));
    }

    // --- The record -----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function every_page_that_is_opened_is_recorded_before_it_is_shown(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->withHeader('User-Agent', 'Staff Browser')->get(route('dashboard'))->assertOk();
        $this->get(route('pricing', ['coupon' => 'SECRET-CODE']))->assertOk();

        $entries = $this->entries('impersonation.viewed');

        $this->assertCount(2, $entries);

        $first = $entries[0];
        $this->assertSame($admin->id, $first->actor_id, 'by the staff member, not the customer');
        $this->assertSame($customer->id, $first->subject_id);
        $this->assertSame('dashboard', $first->meta['route']);
        $this->assertSame('GET', $first->meta['method']);
        $this->assertSame('/dashboard', $first->meta['path']);
        $this->assertSame('Staff Browser', $first->user_agent);

        $this->assertSame('/pricing', $entries[1]->meta['path'], 'the address, never what follows the ?');
        $this->assertStringNotContainsString('SECRET-CODE', json_encode($entries[1]->meta));

        $this->assertSame(
            [$customer->id],
            $first->accounts()->pluck('owner_id')->map(fn ($id) => (int) $id)->all(),
            'it is in the history of the account that was looked at',
        );
        $this->assertSame(2, session(Impersonation::SESSION_KEY.'.views'));
    }

    #[Test]
    public function a_reload_of_part_of_a_page_is_not_a_page_opened(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->viewing($admin, $customer)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'notifications'])->get(route('dashboard'));

        $this->assertSame([], $this->entries()->all());
    }

    #[Test]
    public function a_prefetch_is_not_a_page_opened(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        // (Headers set on a request stay for the next, so this is a test of its own.)
        $this->viewing($admin, $customer)->withHeaders(['Purpose' => 'prefetch'])->get(route('dashboard'));

        $this->assertSame([], $this->entries()->all());
    }

    #[Test]
    public function the_whole_story_is_in_the_history_of_the_account_that_was_looked_at(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->post(route('acp.users.impersonate', $customer), ['reason' => 'Looking into a refund']);
        $this->viewing($admin, $customer)->get(route('dashboard'));
        $this->get(route('security.edit'));
        $this->post(route('impersonation.stop'));

        $this->assertSame(
            ['impersonation.started', 'impersonation.viewed', 'impersonation.blocked', 'impersonation.stopped'],
            AuditLog::query()->concerning(User::class, $customer->id)->orderBy('id')->pluck('event')->all(),
        );
    }

    #[Test]
    public function a_page_that_cannot_be_recorded_is_not_shown(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $audit = $this->mock(Audit::class);
        $audit->shouldReceive('record')->andThrow(new RuntimeException('the log is down'));

        $this->withoutExceptionHandling();
        $this->viewing($admin, $customer);

        try {
            $this->get(route('dashboard'));
            $this->fail('the page was shown without being recorded');
        } catch (RuntimeException $exception) {
            $this->assertSame('the log is down', $exception->getMessage());
        }
    }

    #[Test]
    public function a_view_can_be_ended_even_when_the_log_cannot_be_written(): void
    {
        Exceptions::fake();

        $admin = $this->admin();
        $customer = $this->customer();

        $audit = $this->mock(Audit::class);
        $audit->shouldReceive('record')->andThrow(new RuntimeException('the log is down'));

        $this->viewing($admin, $customer)->post(route('impersonation.stop'))
            ->assertRedirect(route('acp.users.edit', $customer))
            ->assertSessionMissing(Impersonation::SESSION_KEY);

        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'the log is down');
    }

    // --- Where it sits in the stack, and what it covers -----------------------------------------------------------------------------

    #[Test]
    public function the_middleware_runs_after_the_session_starts_and_before_anything_asks_who_is_signed_in(): void
    {
        $router = app('router');
        $checked = 0;

        foreach (['dashboard', 'security.edit', 'shop.orders', 'impersonation.stop', 'home'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', $router->gatherRouteMiddleware($route));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(ApplyImpersonation::class), $name);
            $this->assertLessThan($at(ApplyImpersonation::class), $at(StartSession::class), "{$name}: the session is read");

            foreach ([HandleInertiaRequests::class, PreventBannedUser::class, UpdateLastActivity::class, SubstituteBindings::class, Authenticate::class] as $later) {
                if ($at($later) !== false) {
                    $this->assertLessThan($at($later), $at(ApplyImpersonation::class), "{$name}: before ".class_basename($later));
                }
            }

            $checked++;
        }

        $this->assertSame(5, $checked);
    }

    #[Test]
    public function in_the_api_it_comes_after_the_session_of_a_browser_has_started(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/profile', 'GET'));
        $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', app('router')->gatherRouteMiddleware($route));

        $this->assertLessThan(array_search(ApplyImpersonation::class, $order, true), array_search(EnsureFrontendRequestsAreStateful::class, $order, true));
    }

    #[Test]
    public function every_page_a_customer_can_open_has_been_decided_on(): void
    {
        $viewable = (array) config('impersonation.viewable');
        $blocked = (array) config('impersonation.blocked');

        $this->assertSame([], array_values(array_intersect($viewable, $blocked)), 'a page is viewable or it is not');
        $this->assertSame($viewable, array_values(array_unique($viewable)));
        $this->assertSame($blocked, array_values(array_unique($blocked)));

        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('GET', $route->methods(), true)
                || $uri === 'acp'
                || str_starts_with($uri, 'acp/')
                || in_array('api', $route->middleware(), true)
                || str_starts_with($uri, '_')
                || str_starts_with($uri, 'storage/')) {
                continue;
            }

            $routes[Impersonation::identify($route)] = $uri;
        }

        $undecided = array_diff_key($routes, array_flip([...$viewable, ...$blocked]));

        $this->assertSame([], $undecided, 'These pages are in neither list of config/impersonation.php: decide whether staff may open them while viewing as a customer.');
        $this->assertSame([], array_values(array_diff([...$viewable, ...$blocked], array_keys($routes))), 'These are listed but are no route (renamed or removed?).');
        $this->assertSame([], array_values(array_diff((array) config('impersonation.background'), array_keys($routes))), 'These background requests are listed but are no route.');
        $this->assertGreaterThan(50, count($routes));
    }

    #[Test]
    public function what_hands_over_a_secret_or_reaches_outside_is_never_viewable(): void
    {
        $viewable = (array) config('impersonation.viewable');

        foreach (['security.edit', 'privacy.exports.download', 'settings.billing.invoices.download', 'support.attachments.download', 'shop.downloads.show', 'shop.checkout', 'shop.checkout.complete', 'shop.tebex.continue', 'cashier.payment', 'verification.verify', 'oauth.callback', 'login'] as $name) {
            $this->assertNotContains($name, $viewable, $name);
        }
    }

    // --- The page staff start it from -----------------------------------------------------------------------------------------------

    #[Test]
    public function the_users_page_says_whether_a_view_as_this_person_is_possible_and_if_not_why(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $banned = $this->customer(['email' => 'banned@example.com', 'is_banned' => true]);

        $this->actingAs($admin)->get(route('acp.users.edit', $customer))->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.can', true)
            ->where('impersonation.refusal', null)
            ->where('impersonation.minutes', 30));

        $this->get(route('acp.users.edit', $banned))->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.can', true)
            ->where('impersonation.refusal', 'This account is banned, so it cannot be viewed as.'));

        $this->get(route('acp.users.edit', $admin))->assertInertia(fn (Assert $page) => $page->where('impersonation.refusal', 'You cannot view as yourself.'));

        config(['impersonation.minutes' => 10]);
        $this->get(route('acp.users.edit', $customer))->assertInertia(fn (Assert $page) => $page->where('impersonation.minutes', 10));
    }

    #[Test]
    public function staff_without_the_permission_are_not_offered_it(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('editor'));
        $editor->givePermissionTo(Permission::findOrCreate('users.acp.edit'));

        $this->actingAs($editor)->get(route('acp.users.edit', $this->customer()))->assertInertia(fn (Assert $page) => $page->where('impersonation.can', false));
    }

    #[Test]
    public function the_permission_is_made_by_the_seeder(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'users.acp.impersonate')->exists());
    }

    private function viewableTestRoute(string $name, callable $action): void
    {
        Route::middleware(['web', 'auth'])->get('/__test/'.str_replace('test.', '', $name), $action)->name($name);
        config(['impersonation.viewable' => [...config('impersonation.viewable'), $name]]);
    }
}
