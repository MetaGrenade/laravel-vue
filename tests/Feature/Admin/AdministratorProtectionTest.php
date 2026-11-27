<?php

namespace Tests\Feature\Admin;

use App\Models\PersonalAccessToken;
use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A permission an administrator hands to somebody else opens its own part of the admin area. It is never a way to become an
 * administrator, or to take one's place (docs/acp-permissions.md): by roles, by the administrator's account, or by something that
 * signs in as it.
 */
class AdministratorProtectionTest extends TestCase
{
    use RefreshDatabase;

    /** The writes that name an account, an API key or a role, and are refused for an administrator's by App\Support\Security\Administrators. */
    private const ADMINISTRATORS_ONLY = [
        'DELETE acp/acl/roles/{role}',
        'DELETE acp/tokens/{token}',
        'DELETE acp/users/{user}',
        'DELETE acp/users/{user}/social-accounts/{socialAccount}',
        'PATCH acp/tokens/{token}/revoke',
        'POST acp/users/{user}/social-accounts',
        'PUT acp/acl/roles/{role}',
        'PUT acp/tokens/{token}',
        'PUT acp/users/{user}',
        'PUT acp/users/{user}/verify',
    ];

    /** Those with a rule of their own, which keeps all staff from somebody who is not an administrator. */
    private const A_RULE_FOR_ALL_STAFF = [
        'DELETE acp/users/{user}/two-factor',
        'POST acp/users/{user}/cleanup',
        'POST acp/users/{user}/impersonate',
        'POST acp/users/{user}/warnings',
        'PUT acp/users/{user}/ban',
        'PUT acp/users/{user}/unban',
    ];

    /** Those that name one of these and change nothing about who somebody is or what signs in as them. */
    private const NOT_THE_ACCOUNT = [
        // What may be done with a permission is in DelegatedGrantsTest.
        'DELETE acp/acl/permissions/{permission}',
        'PUT acp/acl/permissions/{permission}',
        // Which support teams somebody is in.
        'PUT acp/support/teams/memberships/{user}',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->admin = User::factory()->create(['nickname' => 'boss', 'email' => 'boss@example.test', 'email_verified_at' => null]);
        $this->admin->syncRoles(['admin']);
    }

    #[Test]
    public function every_admin_write_that_names_an_account_a_key_or_a_role_is_accounted_for(): void
    {
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RegisteredRoute $route) => str_starts_with($route->uri(), 'acp/') && preg_match('/\{(user|token|role|permission)\}/', $route->uri()) === 1)
            ->flatMap(fn (RegisteredRoute $route) => collect($route->methods())
                ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
                ->map(fn (string $method) => "{$method} {$route->uri()}"))
            ->sort()
            ->values()
            ->all();

        $accounted = collect([...self::ADMINISTRATORS_ONLY, ...self::A_RULE_FOR_ALL_STAFF, ...self::NOT_THE_ACCOUNT])->sort()->values()->all();

        // A new one belongs in one of the three lists above, and, unless it changes nothing about the account, behind a check.
        $this->assertSame($accounted, $writes);
    }

    #[Test]
    public function somebody_holding_every_permission_changes_nothing_about_an_administrator(): void
    {
        $holder = $this->staffWith(...Permission::query()->pluck('name')->all());

        $this->admin->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $linked = SocialAccount::query()->create(['user_id' => $this->admin->id, 'provider' => 'google', 'provider_id' => 'the-admins-own']);
        $key = $this->admin->createToken('The admin\'s key', ['profile:read'])->accessToken;
        $role = Role::findByName('admin');

        $payloads = [
            'DELETE acp/acl/roles/{role}' => [],
            'DELETE acp/tokens/{token}' => [],
            'DELETE acp/users/{user}' => [],
            'DELETE acp/users/{user}/social-accounts/{socialAccount}' => ['force' => true],
            'PATCH acp/tokens/{token}/revoke' => [],
            'POST acp/users/{user}/social-accounts' => ['provider' => 'discord', 'provider_id' => 'the-holders-own'],
            'PUT acp/acl/roles/{role}' => ['name' => 'former-admin', 'guard_name' => 'web', 'permissions' => []],
            'PUT acp/tokens/{token}' => ['name' => 'Taken', 'abilities' => ['forum:moderate']],
            'PUT acp/users/{user}' => ['nickname' => 'taken', 'email' => 'holder@example.test', 'roles' => []],
            'PUT acp/users/{user}/verify' => [],
            'DELETE acp/users/{user}/two-factor' => ['reason' => 'Because I say so.'],
            'POST acp/users/{user}/cleanup' => ['reason' => 'Because I say so.'],
            'POST acp/users/{user}/impersonate' => ['reason' => 'Because I say so.'],
            'POST acp/users/{user}/warnings' => ['reason' => 'Because I say so.'],
            'PUT acp/users/{user}/ban' => ['reason' => 'Because I say so.'],
            'PUT acp/users/{user}/unban' => [],
        ];

        $before = $this->snapshot($this->admin);

        foreach ([...self::ADMINISTRATORS_ONLY, ...self::A_RULE_FOR_ALL_STAFF] as $write) {
            [$method, $uri] = explode(' ', $write);

            $uri = strtr($uri, [
                '{user}' => (string) $this->admin->id,
                '{token}' => (string) $key->id,
                '{role}' => (string) $role->id,
                '{socialAccount}' => (string) $linked->id,
            ]);

            $response = $this->actingAs($holder->fresh())->call($method, '/'.$uri, $payloads[$write]);

            if (in_array($write, self::ADMINISTRATORS_ONLY, true)) {
                $this->assertSame(403, $response->status(), $write);
            }

            $this->assertSame($before, $this->snapshot($this->admin), $write);
        }

        $this->assertAuthenticatedAs($holder);
        $this->assertFalse($holder->fresh()->hasRole('admin'));
    }

    #[Test]
    public function editing_members_does_not_let_somebody_give_the_administrator_role(): void
    {
        $editor = $this->staffWith('users.acp.edit');
        $member = User::factory()->create();

        foreach ([$editor, $member] as $target) {
            $this->actingAs($editor->fresh())
                ->put(route('acp.users.update', $target), ['nickname' => 'renamed-'.$target->id, 'email' => $target->email, 'roles' => ['editor', 'admin']])
                ->assertForbidden();

            $this->assertFalse($target->fresh()->hasRole('admin'));
            // Refused as a whole: nothing else in the form was saved either.
            $this->assertSame($target->nickname, $target->fresh()->nickname);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherSpellings(): array
    {
        return ['capitals' => ['ADMIN'], 'one capital' => ['Admin'], 'spaces' => [' admin '], 'an accent' => ['ádmin']];
    }

    #[Test]
    #[DataProvider('otherSpellings')]
    public function another_spelling_of_the_role_does_not_give_it_either(string $spelling): void
    {
        $editor = $this->staffWith('users.acp.edit');
        $member = User::factory()->create();

        $this->actingAs($editor)->put(route('acp.users.update', $member), ['nickname' => $member->nickname, 'email' => $member->email, 'roles' => [$spelling]]);

        $this->assertFalse($member->fresh()->hasRole('admin'));
    }

    #[Test]
    public function editing_members_still_gives_a_role_whose_permissions_the_editor_holds(): void
    {
        $editor = $this->staffWith('users.acp.edit');
        $member = User::factory()->create();
        Role::findOrCreate('helper')->syncPermissions(['polls.acp.view']);

        $this->actingAs($editor)
            ->put(route('acp.users.update', $member), ['nickname' => 'helper', 'email' => $member->email, 'roles' => ['helper']])
            ->assertRedirect(route('acp.users.index'));

        $this->assertSame(['helper'], $member->fresh()->getRoleNames()->all());
        $this->assertSame('helper', $member->fresh()->nickname);
    }

    #[Test]
    public function an_administrator_gives_and_takes_the_role(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('acp.users.update', $member), ['nickname' => $member->nickname, 'email' => $member->email, 'roles' => ['admin']])
            ->assertRedirect(route('acp.users.index'));

        $this->assertTrue($member->fresh()->hasRole('admin'));

        $this->actingAs($this->admin)
            ->put(route('acp.users.update', $member), ['nickname' => $member->nickname, 'email' => $member->email, 'roles' => []])
            ->assertRedirect(route('acp.users.index'));

        $this->assertFalse($member->fresh()->hasRole('admin'));
    }

    #[Test]
    public function the_refusal_is_said_on_the_page_the_form_was_sent_from(): void
    {
        $editor = $this->staffWith('users.acp.edit');

        $this->actingAs($editor)
            ->from(route('acp.users.edit', $this->admin))
            ->put(route('acp.users.update', $this->admin), ['nickname' => 'boss', 'email' => 'boss@example.test'], ['X-Inertia' => 'true'])
            ->assertRedirect(route('acp.users.edit', $this->admin))
            ->assertSessionHasErrors(['refused' => 'Only an administrator can change a staff account.']);
    }

    #[Test]
    public function a_bulk_delete_leaves_administrators_and_deletes_the_rest(): void
    {
        $deleter = $this->staffWith('users.acp.delete');
        $member = User::factory()->create();

        $this->actingAs($deleter)
            ->patch(route('acp.users.bulk-update'), ['action' => 'delete', 'ids' => [$this->admin->id, $member->id]])
            ->assertSessionHas('success', 'Deleted 1 user.')
            ->assertSessionHas('error', 'Not deleted: boss (Only an administrator can change a staff account.).');

        $this->assertModelExists($this->admin);
        $this->assertModelMissing($member);
    }

    #[Test]
    public function a_bulk_verification_leaves_administrators_and_verifies_the_rest(): void
    {
        $verifier = $this->staffWith('users.acp.verify');
        $member = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($verifier)
            ->patch(route('acp.users.bulk-update'), ['action' => 'verify', 'ids' => [$this->admin->id, $member->id]])
            ->assertSessionHas('success', 'Verified 1 user.')
            ->assertSessionHas('error', 'Not verified: boss (Only an administrator can change a staff account.).');

        $this->assertNull($this->admin->fresh()->email_verified_at);
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    #[Test]
    public function an_administrator_deletes_and_verifies_another_in_bulk(): void
    {
        $other = User::factory()->create(['email_verified_at' => null]);
        $other->syncRoles(['admin']);

        $this->actingAs($this->admin)->patch(route('acp.users.bulk-update'), ['action' => 'verify', 'ids' => [$other->id]])->assertSessionMissing('error');
        $this->assertNotNull($other->fresh()->email_verified_at);

        $this->actingAs($this->admin)->patch(route('acp.users.bulk-update'), ['action' => 'delete', 'ids' => [$other->id]])->assertSessionMissing('error');
        $this->assertModelMissing($other);
    }

    #[Test]
    public function a_key_for_an_administrator_is_an_administrators_to_make(): void
    {
        $maker = $this->staffWith('tokens.acp.create');
        $form = ['name' => 'Automation', 'abilities' => ['forum:moderate'], 'user_id' => $this->admin->id];

        $this->actingAs($maker)->post(route('acp.tokens.store'), $form)->assertForbidden()->assertSessionMissing('plain_text_token');
        $this->assertSame(0, PersonalAccessToken::query()->count());
        Notification::assertNothingSent();

        $member = User::factory()->create();

        $this->actingAs($maker)->post(route('acp.tokens.store'), ['user_id' => $member->id] + $form)->assertSessionHas('plain_text_token');
        $this->actingAs($this->admin)->post(route('acp.tokens.store'), $form)->assertSessionHas('plain_text_token');

        $this->assertSame(1, $this->admin->tokens()->count());
    }

    #[Test]
    public function the_same_permissions_still_work_on_a_members_account_and_key(): void
    {
        $holder = $this->staffWith('users.acp.update', 'users.acp.verify', 'users.acp.delete', 'tokens.acp.edit', 'tokens.acp.delete');
        $member = User::factory()->create(['email_verified_at' => null]);
        $key = $member->createToken('Member key', ['profile:read'])->accessToken;

        $this->actingAs($holder)->post(route('acp.users.social-accounts.store', $member), ['provider' => 'discord', 'provider_id' => '4242'])->assertRedirect(route('acp.users.edit', $member));
        $linked = $member->socialAccounts()->sole();
        $this->actingAs($holder)->delete(route('acp.users.social-accounts.destroy', [$member, $linked]), ['force' => true])->assertRedirect(route('acp.users.edit', $member));
        $this->actingAs($holder)->put(route('acp.users.verify', $member))->assertRedirect(route('acp.users.index'));
        $this->actingAs($holder)->put(route('acp.tokens.update', $key), ['name' => 'Renamed', 'abilities' => ['profile:read']])->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($holder)->patch(route('acp.tokens.revoke', $key))->assertRedirect(route('acp.tokens.index'));

        $this->assertSame(0, $member->socialAccounts()->count());
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertSame('Renamed', $key->fresh()->name);
        $this->assertNotNull($key->fresh()->revoked_at);

        $this->actingAs($holder)->delete(route('acp.tokens.destroy', $key))->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($holder)->delete(route('acp.users.destroy', $member))->assertRedirect(route('acp.users.index'));

        $this->assertModelMissing($key);
        $this->assertModelMissing($member);
    }

    #[Test]
    public function an_administrator_changes_another_administrators_account_and_keys(): void
    {
        $other = User::factory()->create();
        $other->syncRoles(['admin']);
        $key = $other->createToken('Key', ['profile:read'])->accessToken;

        $this->actingAs($this->admin)->post(route('acp.users.social-accounts.store', $other), ['provider' => 'discord', 'provider_id' => '1'])->assertRedirect(route('acp.users.edit', $other));
        $this->actingAs($this->admin)->delete(route('acp.users.social-accounts.destroy', [$other, $other->socialAccounts()->sole()]), ['force' => true])->assertRedirect(route('acp.users.edit', $other));
        $this->actingAs($this->admin)->put(route('acp.tokens.update', $key), ['name' => 'Renamed', 'abilities' => ['profile:read']])->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($this->admin)->patch(route('acp.tokens.revoke', $key))->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($this->admin)->delete(route('acp.tokens.destroy', $key))->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($this->admin)->delete(route('acp.users.destroy', $other))->assertRedirect(route('acp.users.index'));

        $this->assertModelMissing($other);
    }

    #[Test]
    public function no_role_can_be_given_the_administrators_name_by_somebody_else(): void
    {
        $holder = $this->staffWith('acl.acp.create', 'acl.acp.edit');
        $editorRole = Role::findByName('editor');

        // As on a site where the role is missing: nobody else can make it, under this spelling or another.
        Role::findByName('admin')->delete();

        $this->actingAs($holder)->post(route('acp.acl.roles.store'), ['name' => 'admin', 'guard_name' => 'web'])->assertForbidden();
        $this->actingAs($holder)->post(route('acp.acl.roles.store'), ['name' => 'Admin', 'guard_name' => 'web'])->assertForbidden();
        $this->actingAs($holder)->put(route('acp.acl.roles.update', $editorRole), ['name' => 'admin', 'guard_name' => 'web'])->assertForbidden();

        $this->assertSame(['editor', 'moderator', 'user'], Role::query()->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function the_other_roles_are_still_managed_with_the_role_permissions(): void
    {
        $holder = $this->staffWith('acl.acp.create', 'acl.acp.edit', 'acl.acp.delete');

        $this->actingAs($holder)->post(route('acp.acl.roles.store'), ['name' => 'helper', 'guard_name' => 'web'])->assertRedirect(route('acp.acl.index'));
        $this->actingAs($holder)->put(route('acp.acl.roles.update', Role::findByName('helper')), ['name' => 'helper', 'guard_name' => 'web', 'permissions' => ['polls.acp.view']])->assertSessionHas('success');
        $this->assertTrue(Role::findByName('helper')->hasPermissionTo('polls.acp.view'));

        $this->actingAs($holder)->delete(route('acp.acl.roles.destroy', Role::findByName('helper')))->assertSessionHas('success');
        $this->assertFalse(Role::query()->where('name', 'helper')->exists());
    }

    #[Test]
    public function an_administrator_changes_the_administrator_role(): void
    {
        $role = Role::findByName('admin');

        $this->actingAs($this->admin)
            ->put(route('acp.acl.roles.update', $role), ['name' => 'admin', 'guard_name' => 'web', 'permissions' => ['polls.acp.view']])
            ->assertSessionHas('success');

        $this->assertSame(['polls.acp.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    private function staffWith(string ...$permissions): User
    {
        $staff = User::factory()->create();
        $staff->syncRoles(['editor']);
        $staff->givePermissionTo($permissions);

        return $staff->fresh();
    }

    /**
     * Everything about an account that somebody taking it over would change.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $user = User::query()->with(['roles', 'socialAccounts', 'tokens'])->findOrFail($user->id);

        return [
            'account' => collect($user->getAttributes())->except(['updated_at', 'last_activity_at'])->all(),
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'role permissions' => Role::findByName('admin')->permissions()->count(),
            'sign-ins' => $user->socialAccounts->map(fn (SocialAccount $account) => "{$account->provider}:{$account->provider_id}")->sort()->values()->all(),
            'keys' => $user->tokens->map(fn ($key) => collect($key->getAttributes())->except(['updated_at'])->all())->values()->all(),
            'warnings and bans' => $user->moderationActions()->count(),
        ];
    }
}
