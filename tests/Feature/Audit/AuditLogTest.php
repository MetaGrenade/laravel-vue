<?php

namespace Tests\Feature\Audit;

use App\Enums\RefundStatus;
use App\Http\Middleware\AuditAdminActions;
use App\Models\AuditLog;
use App\Models\Blog;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Audit\AuditObserver;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use App\Support\OAuth\OAuthProviders;
use App\Support\WebsiteSections;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every change somebody makes in the admin area is written to the audit log, once, and the log cannot be changed (docs/audit-log.md).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private function signInAdmin(): User
    {
        $admin = User::factory()->create(['nickname' => 'Ada Admin', 'email' => 'ada@example.com']);
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * A member of staff who may do only what they are given.
     *
     * @param  list<string>  $permissions
     */
    private function signInEditor(array $permissions = []): User
    {
        $editor = User::factory()->create(['nickname' => 'Ed Editor', 'email' => 'ed@example.com']);
        $editor->assignRole(Role::findOrCreate('editor'));

        foreach ($permissions as $permission) {
            $editor->givePermissionTo(Permission::findOrCreate($permission));
        }

        $this->actingAs($editor);

        return $editor;
    }

    private function entry(): AuditLog
    {
        return AuditLog::query()->sole();
    }

    // --- The log itself -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_entry_is_never_changed(): void
    {
        $entry = AuditLog::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $entry->update(['event' => 'users.unban']);
    }

    #[Test]
    public function an_entry_is_never_deleted(): void
    {
        $entry = AuditLog::factory()->create();

        try {
            $entry->delete();
            $this->fail('the entry was deleted');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertModelExists($entry);
    }

    #[Test]
    public function an_entry_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
        $this->assertNotNull(AuditLog::factory()->create()->created_at);
    }

    // --- What is recorded -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_change_in_the_admin_area_is_recorded_with_who_did_what_to_what_from_where(): void
    {
        $admin = $this->signInAdmin();
        $target = User::factory()->create(['nickname' => 'Troublemaker', 'email' => 'trouble@example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'TestBrowser/1.0')
            ->put(route('acp.users.ban', $target->id))
            ->assertRedirect(route('acp.users.index'));

        $entry = $this->entry();

        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('Ada Admin (ada@example.com)', $entry->actor_label);
        $this->assertSame('users.ban', $entry->event);
        $this->assertSame(AuditLog::SUCCEEDED, $entry->outcome);
        $this->assertSame(User::class, $entry->subject_type);
        $this->assertSame($target->id, $entry->subject_id);
        $this->assertSame('Troublemaker', $entry->subject_label);
        $this->assertSame(User::class, $entry->owner_type);
        $this->assertSame($target->id, $entry->owner_id, 'it is about their account');
        $this->assertSame('198.51.100.20', $entry->ip_address);
        $this->assertSame('TestBrowser/1.0', $entry->user_agent);
        $this->assertTrue($entry->created_at->equalTo(now()));
    }

    #[Test]
    public function the_entry_says_what_changed_as_it_was_and_as_it_became(): void
    {
        $admin = $this->signInAdmin();
        $target = User::factory()->create();

        $this->put(route('acp.users.ban', $target->id));

        $changes = $this->entry()->changes;
        $this->assertCount(1, $changes);

        $change = $changes[0];
        $this->assertSame('User', $change['model']);
        $this->assertSame($target->id, $change['id']);
        $this->assertSame('updated', $change['action']);
        $this->assertFalse($change['old']['is_banned']);
        $this->assertTrue($change['new']['is_banned']);
        $this->assertNull($change['old']['banned_at']);
        $this->assertNotNull($change['new']['banned_at']);
        $this->assertSame($admin->id, $change['new']['banned_by_id']);
        $this->assertArrayNotHasKey('updated_at', $change['new'], 'a timestamp is not what changed');
        $this->assertArrayNotHasKey('owner', $change, 'who owns it is for the entry, not each change');
    }

    #[Test]
    public function a_request_is_described_without_the_values_it_sent(): void
    {
        $this->signInAdmin();
        $target = User::factory()->create();

        $this->put(route('acp.users.update', $target->id), ['_token' => 'a-csrf-token', '_method' => 'PUT', 'nickname' => 'Secretname', 'email' => $target->email, 'roles' => []]);

        $meta = $this->entry()->meta;

        $this->assertSame('acp.users.update', $meta['route']);
        $this->assertSame('PUT', $meta['method']);
        $this->assertSame('/acp/users/'.$target->id, $meta['path']);
        $this->assertSame(302, $meta['status']);
        $this->assertEqualsCanonicalizing(['nickname', 'email', 'roles'], $meta['fields'], 'the names of what was sent');
        $this->assertSame(['user' => $target->id], $meta['models']);
        $this->assertStringNotContainsString('Secretname', json_encode($meta), 'never the values');
    }

    #[Test]
    public function the_events_are_named_after_the_route_and_a_change_to_nothing_is_still_a_change(): void
    {
        $this->signInAdmin();
        $target = User::factory()->create();

        $this->put(route('acp.users.verify', $target->id));
        $this->put(route('acp.users.unban', $target->id));

        $this->assertSame(['users.verify', 'users.unban'], AuditLog::query()->orderBy('id')->pluck('event')->all());
        $this->assertNull(AuditLog::query()->where('event', 'users.unban')->sole()->changes, 'the person was not banned, so nothing changed, but somebody tried');
    }

    #[Test]
    public function looking_at_a_page_is_not_recorded(): void
    {
        $this->signInAdmin();

        $this->get(route('acp.users.index'))->assertOk();
        $this->get(route('acp.dashboard'))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function a_change_outside_the_admin_area_is_not_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['nickname' => 'Changed', 'email' => $user->email])->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function nobody_signed_in_is_not_recorded(): void
    {
        $this->put('/acp/users/1/ban')->assertRedirect(route('login'));

        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function an_address_that_matches_no_route_is_not_recorded(): void
    {
        $this->signInAdmin();

        $this->put('/acp/there-is-nothing-here')->assertNotFound();

        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function a_change_a_customer_makes_to_the_same_records_is_not_recorded(): void
    {
        $user = User::factory()->create();

        // The same model, changed outside an admin request.
        $user->update(['nickname' => 'Renamed by themselves']);

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertFalse(app(Audit::class)->recording());
    }

    // --- How it went ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_request_that_was_refused_is_recorded_as_denied(): void
    {
        $editor = $this->signInEditor();
        $target = User::factory()->create();

        $this->put(route('acp.users.ban', $target->id))->assertForbidden();

        $entry = $this->entry();
        $this->assertSame(AuditLog::DENIED, $entry->outcome);
        $this->assertSame($editor->id, $entry->actor_id);
        $this->assertSame('users.ban', $entry->event);
        $this->assertSame(403, $entry->meta['status']);
        $this->assertNull($entry->changes);
        $this->assertFalse($target->fresh()->is_banned);
    }

    #[Test]
    public function a_request_somebody_with_no_staff_role_made_is_recorded_as_denied(): void
    {
        $member = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($member)->put(route('acp.users.ban', $target->id))->assertForbidden();

        $this->assertSame(AuditLog::DENIED, $this->entry()->outcome, 'somebody trying the admin address is worth knowing about');
    }

    #[Test]
    public function a_request_refused_by_the_routes_own_permission_check_is_recorded_as_denied(): void
    {
        $editor = $this->signInEditor();

        // `acp/system` is behind `can:system.acp.edit` on the route, which is checked before the controller is reached.
        $this->put(route('acp.system.update'), ['maintenance_mode' => true])->assertForbidden();

        $entry = $this->entry();
        $this->assertSame(AuditLog::DENIED, $entry->outcome);
        $this->assertSame($editor->id, $entry->actor_id);
        $this->assertSame('system.update', $entry->event);
    }

    #[Test]
    public function the_audit_middleware_runs_after_authentication_and_before_the_checks_that_can_turn_a_request_away(): void
    {
        $router = app('router');
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'acp/') || array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }

            $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', $router->gatherRouteMiddleware($route));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(AuditAdminActions::class), $route->uri());
            $this->assertLessThan($at(AuditAdminActions::class), $at(Authenticate::class), $route->uri().': signed in first, so a visitor is not recorded');
            $this->assertLessThan($at(SubstituteBindings::class), $at(AuditAdminActions::class), $route->uri().': before the lookup of what it names');

            $authorize = $at(Authorize::class);

            if ($authorize !== false) {
                $this->assertLessThan($authorize, $at(AuditAdminActions::class), $route->uri().': before a `can:` that can refuse it');
            }

            $checked++;
        }

        $this->assertGreaterThan(150, $checked);
    }

    #[Test]
    public function a_request_that_did_not_validate_is_recorded_as_failed(): void
    {
        $this->signInAdmin();

        $this->patch(route('acp.users.bulk-update'), ['action' => 'nonsense', 'ids' => []])->assertSessionHasErrors('action');

        $entry = $this->entry();
        $this->assertSame(AuditLog::FAILED, $entry->outcome);
        $this->assertSame('users.bulk-update', $entry->event);
    }

    #[Test]
    public function a_request_an_api_style_client_is_told_is_invalid_is_recorded_as_failed(): void
    {
        $this->signInAdmin();

        $this->patchJson(route('acp.users.bulk-update'), ['action' => 'nonsense', 'ids' => []])->assertUnprocessable();

        $entry = $this->entry();
        $this->assertSame(AuditLog::FAILED, $entry->outcome);
        $this->assertSame(422, $entry->meta['status']);
    }

    #[Test]
    public function a_request_for_something_that_is_not_there_is_recorded_as_failed(): void
    {
        $this->signInAdmin();

        $this->put('/acp/users/999999/ban')->assertNotFound();

        $entry = $this->entry();
        $this->assertSame(AuditLog::FAILED, $entry->outcome);
        $this->assertSame(404, $entry->meta['status']);
        $this->assertNull($entry->subject_type, 'there was nothing to name');
    }

    #[Test]
    public function a_request_that_ends_with_an_error_to_read_is_recorded_as_failed(): void
    {
        $this->signInAdmin();
        $target = User::factory()->create();
        $this->mock(SubscriptionManager::class)
            ->shouldReceive('deleteUser')->andThrow(new SubscriptionCancellationFailed('Stripe is down'));

        $this->delete(route('acp.users.destroy', $target->id))->assertSessionHas('error');

        $this->assertSame(AuditLog::FAILED, $this->entry()->outcome);
        $this->assertModelExists($target);
    }

    #[Test]
    public function an_error_flashed_by_an_earlier_request_does_not_make_this_one_failed(): void
    {
        $this->signInAdmin();
        $target = User::factory()->create();

        // What the request before flashed can still be read during this one; it says nothing about how this one went.
        $this->withSession(['_flash' => ['old' => ['error', 'errors'], 'new' => []], 'error' => 'Something went wrong before.'])
            ->put(route('acp.users.verify', $target->id));

        $this->assertSame(AuditLog::SUCCEEDED, $this->entry()->outcome);
    }

    #[Test]
    public function a_request_that_crashes_is_recorded_as_failed(): void
    {
        $this->signInAdmin();
        $target = User::factory()->create();
        $this->withoutExceptionHandling();
        User::saving(fn () => throw new RuntimeException('The database fell over.'));

        try {
            $this->put(route('acp.users.verify', $target->id));
            $this->fail('the request should have crashed');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(AuditLog::FAILED, $this->entry()->outcome);
        $this->assertSame(500, $this->entry()->meta['status']);
    }

    #[Test]
    public function a_log_that_cannot_be_written_does_not_fail_a_request_that_has_done_its_work(): void
    {
        Exceptions::fake();
        $this->signInAdmin();
        $target = User::factory()->create();
        AuditLog::creating(fn () => throw new RuntimeException('The disk is full.'));

        $this->put(route('acp.users.ban', $target->id))->assertRedirect(route('acp.users.index'));

        $this->assertTrue($target->fresh()->is_banned, 'the change was made');
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The disk is full.');
    }

    // --- Roles and permissions ------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_roles_a_person_gains_and_loses_are_recorded_by_name(): void
    {
        $this->signInAdmin();
        Role::findOrCreate('editor');
        Role::findOrCreate('moderator');
        $target = User::factory()->create();
        $target->assignRole('moderator');

        $this->put(route('acp.users.update', $target->id), ['nickname' => $target->nickname, 'email' => $target->email, 'roles' => ['editor']])->assertSessionHasNoErrors();

        $changes = collect($this->entry()->changes);

        $this->assertSame(['editor'], $changes->firstWhere('action', 'roles.attached')['values']);
        $this->assertSame(['moderator'], $changes->firstWhere('action', 'roles.detached')['values']);
        $this->assertSame('User', $changes->firstWhere('action', 'roles.attached')['model']);
        $this->assertSame($target->id, $changes->firstWhere('action', 'roles.attached')['id']);
    }

    #[Test]
    public function saving_somebody_with_the_roles_they_already_have_records_no_role_change(): void
    {
        $this->signInAdmin();
        Role::findOrCreate('editor');
        $target = User::factory()->create();
        $target->assignRole('editor');

        $this->put(route('acp.users.update', $target->id), ['nickname' => 'New name', 'email' => $target->email, 'roles' => ['editor']])->assertSessionHasNoErrors();

        $actions = collect($this->entry()->changes)->pluck('action')->all();

        $this->assertSame(['updated'], $actions, 'only the name changed');
    }

    #[Test]
    public function a_password_is_never_written_down(): void
    {
        $audit = app(Audit::class);
        $audit->begin($this->signInAdmin());

        User::factory()->create(['nickname' => 'Newcomer', 'password' => 'a-long-secret-password']);
        User::query()->where('nickname', 'Newcomer')->firstOrFail()->update(['password' => 'another-long-secret']);

        $entry = $audit->finish(request(), 200);

        $created = collect($entry->changes)->firstWhere('action', 'created');
        $updated = collect($entry->changes)->firstWhere('action', 'updated');

        $this->assertSame('Newcomer', $created['new']['nickname']);
        $this->assertSame('[hidden]', $created['new']['password']);
        $this->assertSame('[hidden]', $updated['old']['password'], 'a change to it is recorded, but not what it was or became');
        $this->assertSame('[hidden]', $updated['new']['password']);
        $this->assertStringNotContainsString('secret', json_encode($entry->toArray()));
    }

    #[Test]
    public function the_permissions_a_role_gains_and_loses_are_recorded(): void
    {
        $this->signInAdmin();
        $role = Role::findOrCreate('support-lead');
        $role->givePermissionTo([Permission::findOrCreate('blogs.acp.view'), Permission::findOrCreate('blogs.acp.edit')]);
        Permission::findOrCreate('forums.acp.view');

        $this->put(route('acp.acl.roles.update', $role->id), ['name' => 'support-lead', 'guard_name' => 'web', 'permissions' => ['blogs.acp.view', 'forums.acp.view']])->assertSessionHasNoErrors();

        $changes = collect($this->entry()->changes);

        $this->assertSame(['forums.acp.view'], $changes->firstWhere('action', 'permissions.attached')['values']);
        $this->assertSame(['blogs.acp.edit'], $changes->firstWhere('action', 'permissions.detached')['values']);
        $this->assertSame('Role', $changes->firstWhere('action', 'permissions.attached')['model']);
        $this->assertSame('support-lead', $changes->firstWhere('action', 'permissions.attached')['label']);
    }

    #[Test]
    public function a_record_that_is_deleted_is_recorded_as_it_was(): void
    {
        $this->signInAdmin();
        $role = Role::findOrCreate('temporary');

        $this->delete(route('acp.acl.roles.destroy', $role->id))->assertRedirect();

        $entry = $this->entry();
        $change = collect($entry->changes)->firstWhere('action', 'deleted');

        $this->assertSame('acl.roles.destroy', $entry->event);
        $this->assertSame('Role', $change['model']);
        $this->assertSame('temporary', $change['label']);
        $this->assertSame('temporary', $change['old']['name']);
        $this->assertSame([], $change['new']);
        $this->assertSame($role->id, $entry->subject_id, 'the address named it, and it is still named though it is gone');
    }

    #[Test]
    public function a_change_to_nothing_but_what_is_not_worth_noting_is_not_a_change(): void
    {
        $audit = app(Audit::class);
        $admin = $this->signInAdmin();
        $user = User::factory()->create();

        $audit->begin($admin);
        $user->forceFill(['last_activity_at' => now()->addMinute()])->save();
        $user->touch();
        $entry = $audit->finish(request(), 200);

        $this->assertNull($entry->changes, 'the last time somebody was seen, and a timestamp, say nothing');
    }

    #[Test]
    public function whose_account_a_record_is_on_is_read_from_the_record(): void
    {
        $user = User::factory()->create();

        $owned = (new Order)->forceFill(['owner_type' => User::class, 'owner_id' => 12]);
        $token = (new PersonalAccessToken)->forceFill(['tokenable_type' => User::class, 'tokenable_id' => 13]);
        $theirs = (new SupportTicket)->forceFill(['user_id' => 14]);
        $nobodys = new Role;

        $this->assertSame([User::class, $user->id], Audit::ownerOf($user));
        $this->assertSame([User::class, 12], Audit::ownerOf($owned));
        $this->assertSame([User::class, 13], Audit::ownerOf($token));
        $this->assertSame([User::class, 14], Audit::ownerOf($theirs));
        $this->assertNull(Audit::ownerOf($nobodys));
    }

    #[Test]
    public function every_kind_of_value_is_kept_as_something_plain(): void
    {
        $this->assertSame('{"a":1,"b":[2,3]}', Audit::scalar(['a' => 1, 'b' => [2, 3]]));
        $this->assertSame('failed', Audit::scalar(RefundStatus::Failed));
        $this->assertSame('text', Audit::scalar(new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'text';
            }
        }));
        $this->assertSame('[stdClass]', Audit::scalar(new \stdClass));
        $this->assertSame(1.5, Audit::scalar(1.5));
    }

    // --- Settings -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_change_to_the_system_settings_says_what_each_was_and_became(): void
    {
        $this->signInAdmin();
        SystemSetting::set('maintenance_mode', false);

        $this->put(route('acp.system.update'), [
            'maintenance_mode' => true,
            'email_verification_required' => false,
            'website_sections' => WebsiteSections::defaults(),
            'oauth_providers' => array_fill_keys(OAuthProviders::keys(), false),
        ])->assertSessionHasNoErrors();

        $changes = collect($this->entry()->changes);

        $maintenance = $changes->first(fn (array $change) => $change['label'] === 'maintenance_mode');
        $this->assertSame('updated', $maintenance['action']);
        $this->assertFalse($maintenance['old']['value']);
        $this->assertTrue($maintenance['new']['value']);

        $verification = $changes->first(fn (array $change) => $change['label'] === 'email_verification_required');
        $this->assertSame('created', $verification['action'], 'a setting that had never been saved');
        $this->assertSame('system.update', $this->entry()->event);
    }

    // --- Secrets and size -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_token_staff_make_is_recorded_without_its_secret(): void
    {
        $admin = $this->signInAdmin();
        $owner = User::factory()->create(['nickname' => 'Owner']);

        $this->post(route('acp.tokens.store'), ['name' => 'For them', 'user_id' => $owner->id, 'abilities' => ['profile:read'], 'expires_at' => null])
            ->assertSessionHas('plain_text_token');

        $entry = $this->entry();
        $created = collect($entry->changes)->first(fn (array $change) => $change['model'] === 'PersonalAccessToken');

        $this->assertSame('created', $created['action']);
        $this->assertSame('For them', $created['label']);
        $this->assertSame('[hidden]', $created['new']['token']);
        $this->assertSame($owner->id, $created['new']['tokenable_id'], 'whose it is is not hidden');
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($owner->id, $entry->owner_id, 'it is on their account');
        $this->assertStringNotContainsString(explode('|', session('plain_text_token'))[1], json_encode($entry->toArray()));
    }

    #[Test]
    public function an_attribute_is_hidden_by_what_it_is_called_whatever_the_model_says(): void
    {
        $observer = new \ReflectionMethod(AuditObserver::class, 'isSecret');
        $secret = fn (string $attribute) => $observer->invoke(new AuditObserver, new Blog, $attribute);

        foreach (['password', 'two_factor_secret', 'token', 'remember_token', 'api_token', 'client_secret', 'stripe_api_key', 'recovery_codes'] as $name) {
            $this->assertTrue($secret($name), $name);
        }

        foreach (['tokenable_id', 'tokenable_type', 'nickname', 'stripe_id', 'is_banned'] as $name) {
            $this->assertFalse($secret($name), $name);
        }
    }

    #[Test]
    public function a_long_value_is_cut_short(): void
    {
        config(['audit.max_value_length' => 20]);

        $this->assertSame(str_repeat('a', 20).'…', Audit::scalar(str_repeat('a', 50)));
        $this->assertSame('short', Audit::scalar('short'));
        $this->assertSame(5, Audit::scalar(5));
        $this->assertNull(Audit::scalar(null));
        $this->assertTrue(Audit::scalar(true));
        $this->assertSame('2026-10-15T12:00:00+00:00', Audit::scalar(now()));
    }

    #[Test]
    public function a_request_that_changes_hundreds_of_records_keeps_the_first_and_says_how_many_it_did_not(): void
    {
        config(['audit.max_changes' => 3]);
        $this->signInAdmin();
        $users = User::factory()->count(6)->create();

        $this->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => $users->pluck('id')->all()])->assertSessionHasNoErrors();

        $entry = $this->entry();
        $this->assertCount(3, $entry->changes);
        $this->assertSame(3, $entry->meta['changes_not_kept']);
        $this->assertSame(6, User::query()->where('is_banned', true)->count(), 'everybody was still banned');
    }

    #[Test]
    public function nothing_is_written_when_nobody_was_signed_in_whatever_reached_the_log(): void
    {
        $audit = app(Audit::class);
        $audit->begin(null);
        User::factory()->create();

        $this->assertNull($audit->finish(request(), 200));
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function the_middleware_records_nothing_for_a_request_with_no_route(): void
    {
        $admin = $this->signInAdmin();
        $request = Request::create('/acp/anything', 'PUT');
        $request->setUserResolver(fn () => $admin);

        $response = app(AuditAdminActions::class)->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function the_account_an_entry_is_about_is_the_one_named_not_the_first_one_to_change(): void
    {
        $admin = $this->signInAdmin();
        $named = User::factory()->create();
        $other = User::factory()->create();
        $audit = app(Audit::class);

        $audit->begin($admin);
        $audit->subject($named);
        $other->update(['nickname' => 'Changed on the way']);
        $entry = $audit->finish(request(), 200);

        $this->assertSame($named->id, $entry->owner_id);
        $this->assertSame($named->id, $entry->subject_id);
    }

    #[Test]
    public function the_account_is_the_first_one_to_change_when_the_address_names_nothing(): void
    {
        $admin = $this->signInAdmin();
        $other = User::factory()->create();
        $audit = app(Audit::class);

        $audit->begin($admin);
        $other->update(['nickname' => 'Changed']);
        $entry = $audit->finish(request(), 200);

        $this->assertNull($entry->subject_type);
        $this->assertSame($other->id, $entry->owner_id);
    }

    #[Test]
    public function a_record_that_is_made_is_recorded_without_its_timestamps(): void
    {
        $audit = app(Audit::class);
        $audit->begin($this->signInAdmin());

        User::factory()->create(['nickname' => 'Newcomer']);
        $entry = $audit->finish(request(), 200);

        $created = collect($entry->changes)->firstWhere('action', 'created');

        $this->assertSame('Newcomer', $created['new']['nickname']);
        $this->assertArrayNotHasKey('created_at', $created['new']);
        $this->assertArrayNotHasKey('updated_at', $created['new']);
    }

    // --- Coverage -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function every_change_in_the_admin_area_is_covered_by_the_address_not_by_a_tag_on_the_route(): void
    {
        $uncovered = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

            if (! $writes || ! str_starts_with($route->uri(), 'acp/')) {
                continue;
            }

            $request = Request::create('/'.$route->uri(), $route->methods()[0]);
            $request->setRouteResolver(fn () => $route);

            $covered = (new \ReflectionMethod(AuditAdminActions::class, 'isAdminChange'))
                ->invoke(new AuditAdminActions, $request);

            $checked++;

            if (! $covered) {
                $uncovered[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $uncovered);
        $this->assertGreaterThan(150, $checked, 'the admin area has well over 150 changes to make');
    }

    #[Test]
    public function the_middleware_is_in_the_web_group_so_it_sees_every_admin_request(): void
    {
        $this->assertContains(AuditAdminActions::class, app('router')->getMiddlewareGroups()['web']);
    }

    #[Test]
    public function roles_and_permissions_are_only_changed_in_the_admin_area_through_the_audit_tracking(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers/Admin'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/->(syncRoles|assignRole|removeRole|syncPermissions|givePermissionTo|revokePermissionTo)\(/', $line) && ! str_contains($line, '->tracking(')) {
                    $offenders[] = basename($file->getPathname()).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'wrap it in app(Audit::class)->tracking($model, \'roles\' or \'permissions\', fn () => ...) so the log says what really changed');
    }
}
