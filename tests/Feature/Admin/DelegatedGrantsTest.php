<?php

namespace Tests\Feature\Admin;

use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use MetaForge\Discord\Models\DiscordRoleMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Somebody who is not an administrator gives only permissions they hold themselves, and takes none away from somebody who has them
 * (docs/acp-permissions.md): through a member's roles, through the roles and permissions themselves, and through a Discord account
 * linked by hand.
 */
class DelegatedGrantsTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_HELD = 'You can only give permissions you hold yourself.';

    private const GRANT = 'Only an administrator can give the admin, editor or moderator role.';

    private const THE_ROLE = 'Only an administrator can change or delete the admin, editor and moderator roles.';

    private const RENAME = 'Only an administrator can rename a role or a permission, or change its guard.';

    private const ROLE_IN_USE = 'Somebody has this role, so only an administrator can take permissions off it or delete it.';

    private const PERMISSION_IN_USE = 'This permission is in use, so only an administrator can delete it.';

    private const PROVIDER = 'Discord gives roles to the account linked to it, some of which you cannot give. An administrator has to link it.';

    private User $admin;

    /** An editor handed the members, and the roles and permissions. */
    private User $delegate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->syncRoles(['admin']);

        $this->delegate = User::factory()->create();
        $this->delegate->syncRoles(['editor']);
        $this->delegate->givePermissionTo('users.acp.view', 'users.acp.edit', 'users.acp.update', 'acl.acp.view', 'acl.acp.create', 'acl.acp.edit', 'acl.acp.delete');

        Permission::findOrCreate('clubhouse.enter');
        // An editor holds polls.acp.view; nobody but an administrator holds the other two.
        Role::findOrCreate('helper')->syncPermissions(['polls.acp.view']);
        Role::findOrCreate('finance')->syncPermissions(['billing.acp.view']);
        Role::findOrCreate('club')->syncPermissions(['clubhouse.enter']);
    }

    #[Test]
    public function a_role_whose_permissions_they_all_hold_is_theirs_to_give(): void
    {
        $member = User::factory()->create();

        $this->giveRoles($member, ['helper', 'user'])->assertRedirect(route('acp.users.index'));

        $this->assertSame(['helper', 'user'], $this->rolesOf($member));
    }

    /**
     * @return array<string, array{array<int, string>}>
     */
    public static function rolesBeyondThem(): array
    {
        return [
            'one that opens an admin page' => [['finance']],
            'one with a permission outside the admin area' => [['club']],
            'beside one they could give' => [['helper', 'finance']],
        ];
    }

    /**
     * @param  array<int, string>  $roles
     */
    #[Test]
    #[DataProvider('rolesBeyondThem')]
    public function a_role_with_a_permission_they_do_not_hold_is_not_theirs_to_give(array $roles): void
    {
        $member = User::factory()->create(['nickname' => 'quiet']);

        $this->giveRoles($member, $roles, 'loud')->assertForbidden()->assertSee(self::NOT_HELD);

        $this->assertSame([], $this->rolesOf($member));
        // Refused as a whole: nothing else in the form was saved either.
        $this->assertSame('quiet', $member->fresh()->nickname);
    }

    #[Test]
    #[TestWith(['admin'])]
    #[TestWith(['editor'])]
    #[TestWith(['moderator'])]
    public function a_staff_role_is_an_administrators_to_give(string $role): void
    {
        $member = User::factory()->create();
        // Holding everything the role holds does not change that.
        $this->delegate->givePermissionTo(Permission::query()->get());

        $this->giveRoles($member, [$role])->assertForbidden()->assertSee(self::GRANT);

        $this->assertSame([], $this->rolesOf($member));
    }

    #[Test]
    public function a_role_an_account_has_already_is_not_being_given(): void
    {
        $member = User::factory()->create();
        $member->assignRole('club');

        $this->giveRoles($member, ['club', 'user'])->assertRedirect(route('acp.users.index'));
        $this->assertSame(['club', 'user'], $this->rolesOf($member));

        // Taking it off gives nobody anything. Putting it back would.
        $this->giveRoles($member, ['user'])->assertRedirect(route('acp.users.index'));
        $this->giveRoles($member, ['user', 'club'])->assertForbidden();
        $this->assertSame(['user'], $this->rolesOf($member));
    }

    #[Test]
    public function a_member_given_a_permission_of_the_admin_area_is_staff_from_then_on(): void
    {
        $member = User::factory()->create();

        $this->giveRoles($member, ['helper'])->assertRedirect(route('acp.users.index'));

        // So taking it back is an administrator's.
        $this->giveRoles($member, [])->assertForbidden()->assertSee('Only an administrator can change a staff account.');
        $this->assertSame(['helper'], $this->rolesOf($member));
    }

    #[Test]
    public function a_new_role_has_only_permissions_its_maker_holds(): void
    {
        $this->makeRole('quizmaster', ['polls.acp.view', 'polls.acp.edit'])->assertRedirect(route('acp.acl.index'));
        $this->makeRole('treasurer', ['polls.acp.view', 'billing.acp.view'])->assertForbidden()->assertSee(self::NOT_HELD);

        $this->assertSame(['polls.acp.edit', 'polls.acp.view'], $this->permissionsOf('quizmaster'));
        $this->assertFalse(Role::query()->where('name', 'treasurer')->exists());
    }

    #[Test]
    public function a_new_role_does_not_take_a_staff_roles_name(): void
    {
        Role::findByName('moderator')->delete();

        $this->makeRole('Moderator', [])->assertForbidden()->assertSee(self::THE_ROLE);

        $this->assertFalse(Role::query()->where('name', 'Moderator')->exists());
    }

    #[Test]
    public function a_role_is_given_only_permissions_the_person_changing_it_holds(): void
    {
        // What it has already stays, held or not.
        $this->saveRole('finance', ['billing.acp.view', 'polls.acp.view'])->assertSessionHas('success');
        $this->saveRole('helper', ['polls.acp.view', 'billing.acp.view'])->assertForbidden()->assertSee(self::NOT_HELD);

        $this->assertSame(['billing.acp.view', 'polls.acp.view'], $this->permissionsOf('finance'));
        $this->assertSame(['polls.acp.view'], $this->permissionsOf('helper'));
    }

    #[Test]
    public function a_role_nobody_has_can_be_trimmed_and_deleted(): void
    {
        $this->saveRole('finance', [])->assertSessionHas('success');
        $this->assertSame([], $this->permissionsOf('finance'));

        $this->actingAs($this->delegate->fresh())->delete(route('acp.acl.roles.destroy', Role::findByName('club')))->assertSessionHas('success');
        $this->assertFalse(Role::query()->where('name', 'club')->exists());
    }

    #[Test]
    public function nothing_is_taken_from_a_role_somebody_has(): void
    {
        User::factory()->create()->assignRole('helper');

        $this->saveRole('helper', [])->assertForbidden()->assertSee(self::ROLE_IN_USE);
        // Swapping one for another takes the first away.
        $this->saveRole('helper', ['polls.acp.edit'])->assertForbidden()->assertSee(self::ROLE_IN_USE);
        $this->actingAs($this->delegate->fresh())->delete(route('acp.acl.roles.destroy', Role::findByName('helper')))->assertForbidden()->assertSee(self::ROLE_IN_USE);
        $this->assertSame(['polls.acp.view'], $this->permissionsOf('helper'));

        // Saved as it is, or with more, it takes nothing away.
        $this->saveRole('helper', ['polls.acp.view'])->assertSessionHas('success');
        $this->saveRole('helper', ['polls.acp.view', 'polls.acp.edit'])->assertSessionHas('success');
        $this->assertSame(['polls.acp.edit', 'polls.acp.view'], $this->permissionsOf('helper'));
    }

    #[Test]
    #[TestWith(['editor'])]
    #[TestWith(['moderator'])]
    public function the_editor_and_moderator_roles_are_an_administrators_to_change(string $role): void
    {
        // The delegate holds the other staff role, and nobody holds this one, so only the role itself is the reason.
        $this->delegate->syncRoles([$role === 'editor' ? 'moderator' : 'editor']);
        User::role($role)->get()->each->removeRole($role);
        $this->delegate->assignRole('helper');
        $this->delegate->givePermissionTo('polls.acp.view', 'polls.acp.delete');
        $before = $this->permissionsOf($role);

        $this->saveRole($role, [...$before, 'polls.acp.delete'])->assertForbidden()->assertSee(self::THE_ROLE);
        $this->saveRole($role, [])->assertForbidden()->assertSee(self::THE_ROLE);
        $this->actingAs($this->delegate->fresh())->delete(route('acp.acl.roles.destroy', Role::findByName($role)))->assertForbidden()->assertSee(self::THE_ROLE);

        $this->assertSame($before, $this->permissionsOf($role));
    }

    #[Test]
    public function roles_and_permissions_keep_their_names_and_guards(): void
    {
        $role = Role::findByName('helper');
        $permission = Permission::findByName('polls.acp.view');
        $actor = fn () => $this->actingAs($this->delegate->fresh());

        $actor()->put(route('acp.acl.roles.update', $role), ['name' => 'helpers', 'guard_name' => 'web', 'permissions' => ['polls.acp.view']])->assertForbidden()->assertSee(self::RENAME);
        $actor()->put(route('acp.acl.roles.update', $role), ['name' => 'helper', 'guard_name' => 'api', 'permissions' => ['polls.acp.view']])->assertForbidden()->assertSee(self::RENAME);
        // Renaming one they hold into one they do not would give it to them, and to everybody else who holds it.
        $actor()->put(route('acp.acl.permissions.update', $permission), ['name' => 'tokens.acp.mint', 'guard_name' => 'web'])->assertForbidden()->assertSee(self::RENAME);
        $actor()->put(route('acp.acl.permissions.update', $permission), ['name' => 'polls.acp.view', 'guard_name' => 'api'])->assertForbidden()->assertSee(self::RENAME);

        $this->assertSame(['helper', 'web'], [$role->fresh()->name, $role->fresh()->guard_name]);
        $this->assertSame(['polls.acp.view', 'web'], [$permission->fresh()->name, $permission->fresh()->guard_name]);
    }

    #[Test]
    public function only_a_permission_nobody_has_is_deleted(): void
    {
        $onARole = Permission::findByName('billing.acp.view');
        $onAnAccount = Permission::findOrCreate('garden.enter');
        User::factory()->create()->givePermissionTo($onAnAccount);
        $unused = Permission::findOrCreate('attic.enter');
        $actor = fn () => $this->actingAs($this->delegate->fresh());

        // The seeder gives billing.acp.view to the administrator role as well; take that away to leave the one role.
        Role::findByName('admin')->revokePermissionTo($onARole);

        $actor()->delete(route('acp.acl.permissions.destroy', $onARole))->assertForbidden()->assertSee(self::PERMISSION_IN_USE);
        $actor()->delete(route('acp.acl.permissions.destroy', $onAnAccount))->assertForbidden()->assertSee(self::PERMISSION_IN_USE);
        $actor()->delete(route('acp.acl.permissions.destroy', $unused))->assertSessionHas('success');

        $this->assertModelExists($onARole);
        $this->assertModelExists($onAnAccount);
        $this->assertModelMissing($unused);
    }

    #[Test]
    public function a_new_permission_gives_nobody_anything_and_cannot_be_given_by_its_maker(): void
    {
        $this->actingAs($this->delegate)->post(route('acp.acl.permissions.store'), ['name' => 'vault.acp.open', 'guard_name' => 'web'])->assertRedirect(route('acp.acl.index'));

        $this->saveRole('helper', ['polls.acp.view', 'vault.acp.open'])->assertForbidden()->assertSee(self::NOT_HELD);
        $this->assertFalse($this->delegate->fresh()->can('vault.acp.open'));
    }

    #[Test]
    public function none_of_it_raises_the_person_doing_it(): void
    {
        $actor = fn () => $this->actingAs($this->delegate->fresh());
        $this->delegate->assignRole('helper');
        $wanted = ['name' => 'tokens.acp.create', 'guard_name' => 'web'];

        // Their own roles, a role they have, a staff role they have, a new role, and a permission renamed into the one they want.
        $this->giveRoles($this->delegate, ['editor', 'helper', 'finance']);
        $this->saveRole('helper', ['polls.acp.view', 'tokens.acp.create']);
        $this->saveRole('editor', ['tokens.acp.create']);
        $this->makeRole('keymaker', ['tokens.acp.create']);
        $actor()->delete(route('acp.acl.permissions.destroy', Permission::findByName('tokens.acp.create')));
        $actor()->put(route('acp.acl.permissions.update', Permission::findByName('polls.acp.view')), $wanted);

        $now = $this->delegate->fresh();
        $this->assertFalse($now->can('tokens.acp.create'));
        $this->assertFalse($now->can('billing.acp.view'));
        $this->assertSame(['editor', 'helper'], $this->rolesOf($now));
    }

    #[Test]
    #[TestWith(['finance', false])]
    // Holding everything a staff role holds does not make it theirs to give.
    #[TestWith(['moderator', true])]
    public function a_discord_account_that_would_be_given_such_a_role_is_an_administrators_to_link(string $role, bool $holdsEverything): void
    {
        $this->delegate->givePermissionTo($holdsEverything ? Permission::query()->get() : []);
        $this->mapping($role, fromDiscord: true);
        $member = User::factory()->create();

        $this->linkDiscord($this->delegate, $member)->assertForbidden()->assertSee(self::PROVIDER);
        $this->assertSame(0, SocialAccount::query()->count());

        $this->linkDiscord($this->admin, $member)->assertRedirect(route('acp.users.edit', $member));
        $this->assertSame(1, $member->socialAccounts()->count());
    }

    #[Test]
    public function a_discord_account_is_linked_when_what_discord_gives_is_theirs_to_give(): void
    {
        $this->mapping('helper', fromDiscord: true);
        // Sent to Discord only: linking an account is given nothing by it.
        $this->mapping('finance', fromDiscord: false, discordRole: '101');
        $member = User::factory()->create();

        $this->linkDiscord($this->delegate, $member)->assertRedirect(route('acp.users.edit', $member));

        $this->assertSame(1, $member->socialAccounts()->count());
    }

    #[Test]
    public function another_provider_is_not_given_discords_roles(): void
    {
        config(['discord.social_provider' => 'something-else']);
        $this->mapping('finance', fromDiscord: true);
        $member = User::factory()->create();

        $this->linkDiscord($this->delegate, $member)->assertRedirect(route('acp.users.edit', $member));
    }

    #[Test]
    public function an_administrator_does_all_of_it_whatever_their_role_lists(): void
    {
        Role::findByName('admin')->syncPermissions([]);
        User::factory()->create()->assignRole('helper');
        $member = User::factory()->create();
        $actor = fn () => $this->actingAs($this->admin->fresh());

        $actor()->put(route('acp.users.update', $member), ['nickname' => $member->nickname, 'email' => $member->email, 'roles' => ['finance', 'moderator']])->assertRedirect(route('acp.users.index'));
        $actor()->post(route('acp.acl.roles.store'), ['name' => 'treasury', 'guard_name' => 'web', 'permissions' => ['billing.acp.view']])->assertRedirect(route('acp.acl.index'));
        $actor()->put(route('acp.acl.roles.update', Role::findByName('helper')), ['name' => 'helpers', 'guard_name' => 'web', 'permissions' => ['billing.acp.view']])->assertSessionHas('success');
        $actor()->put(route('acp.acl.permissions.update', Permission::findByName('polls.acp.view')), ['name' => 'polls.acp.look', 'guard_name' => 'web'])->assertSessionHas('success');
        $actor()->delete(route('acp.acl.permissions.destroy', Permission::findByName('billing.acp.view')))->assertSessionHas('success');
        $actor()->delete(route('acp.acl.roles.destroy', Role::findByName('helpers')))->assertSessionHas('success');
        $actor()->delete(route('acp.acl.roles.destroy', Role::findByName('editor')))->assertSessionHas('success');

        $this->assertSame(['finance', 'moderator'], $this->rolesOf($member));
    }

    /**
     * Spatie reads a string shaped like a UUID or a ULID as a record's id, and a database may make a number of it: MariaDB and MySQL
     * of the digits any of these begins with, others of a ULID that is all digits.
     *
     * @return array<string, array{string}>
     */
    public static function shapesOfAnId(): array
    {
        return [
            'a ULID of digits' => ['ulid of digits'],
            'a ULID with letters' => ['ulid'],
            'a UUID' => ['uuid'],
        ];
    }

    #[Test]
    #[DataProvider('shapesOfAnId')]
    public function no_role_or_permission_is_named_like_an_id(string $shape): void
    {
        $name = $this->nameShaped($shape, Role::findByName('admin')->id);
        $role = Role::findByName('helper');
        $permission = Permission::findByName('clubhouse.enter');
        $actor = fn () => $this->actingAs($this->admin->fresh());
        $before = [Role::query()->count(), Permission::query()->count()];

        $actor()->post(route('acp.acl.roles.store'), ['name' => $name, 'guard_name' => 'web'])->assertSessionHasErrors('name');
        $actor()->put(route('acp.acl.roles.update', $role), ['name' => $name, 'guard_name' => 'web'])->assertSessionHasErrors('name');
        $actor()->post(route('acp.acl.permissions.store'), ['name' => $name, 'guard_name' => 'web'])->assertSessionHasErrors('name');
        $actor()->put(route('acp.acl.permissions.update', $permission), ['name' => $name, 'guard_name' => 'web'])->assertSessionHasErrors('name');

        $this->assertSame($before, [Role::query()->count(), Permission::query()->count()]);
        $this->assertSame(['helper', 'clubhouse.enter'], [$role->fresh()->name, $permission->fresh()->name]);
    }

    /** One that was made before the names were refused: giving it gives that role, not the one with the number in its name. */
    #[Test]
    #[DataProvider('shapesOfAnId')]
    public function a_role_named_like_an_id_is_given_as_itself(string $shape): void
    {
        $name = $this->nameShaped($shape, Role::findByName('admin')->id);
        Role::query()->create(['name' => $name, 'guard_name' => 'web']);
        $member = User::factory()->create();

        $this->giveRoles($member, [$name])->assertRedirect(route('acp.users.index'));

        $this->assertSame([$name], $this->rolesOf($member));
    }

    #[Test]
    #[DataProvider('shapesOfAnId')]
    public function a_permission_named_like_an_id_is_put_on_a_role_as_itself(string $shape): void
    {
        $name = $this->nameShaped($shape, Permission::findByName('billing.acp.view')->id);
        Permission::create(['name' => $name]);
        $actor = fn () => $this->actingAs($this->admin->fresh());

        $actor()->put(route('acp.acl.roles.update', Role::findByName('helper')), ['name' => 'helper', 'guard_name' => 'web', 'permissions' => [$name]])->assertSessionHas('success');
        $actor()->post(route('acp.acl.roles.store'), ['name' => 'fresh', 'guard_name' => 'web', 'permissions' => [$name]])->assertRedirect(route('acp.acl.index'));

        $this->assertSame([$name], $this->permissionsOf('helper'));
        $this->assertSame([$name], $this->permissionsOf('fresh'));
    }

    #[Test]
    public function the_roles_page_says_what_the_person_looking_may_do(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        User::factory()->create()->assignRole('helper');
        // Held by no role and no account, and first in the list.
        Permission::findOrCreate('attic.enter');
        $roles = fn (Collection $items) => $items->mapWithKeys(fn (array $role) => [$role['name'] => [$role['can_edit'], $role['can_take_away']]])->sortKeys()->all();
        $permissions = fn (Collection $items) => $items->pluck('can_delete', 'name')->only(['acl.acp.create', 'attic.enter'])->sortKeys()->all();

        $this->actingAs($this->delegate->fresh())->get(route('acp.acl.index'))->assertInertia(fn ($page) => $page
            ->where('roles.data', fn (Collection $items) => $roles($items) === [
                'admin' => [false, false],
                'club' => [true, true],
                'editor' => [false, false],
                'finance' => [true, true],
                'helper' => [true, false],
                'moderator' => [false, false],
                'user' => [true, true],
            ])
            ->where('permissions.data', fn (Collection $items) => $permissions($items) === ['acl.acp.create' => false, 'attic.enter' => true])
            ->where('canRename', false)
            ->where('grantable', fn (Collection $names) => $names->contains('polls.acp.view') && ! $names->contains('billing.acp.view')));

        $this->actingAs($this->admin)->get(route('acp.acl.index'))->assertInertia(fn ($page) => $page
            ->where('roles.data', fn (Collection $items) => collect($roles($items))->flatten()->unique()->values()->all() === [true])
            ->where('permissions.data', fn (Collection $items) => $items->pluck('can_delete')->unique()->values()->all() === [true])
            ->where('canRename', true)
            ->where('grantable', null));
    }

    #[Test]
    public function the_pages_that_give_roles_and_permissions_say_which_the_person_may(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        $member = User::factory()->create();
        $member->assignRole('club');

        $this->actingAs($this->delegate->fresh())->get(route('acp.acl.roles.create'))->assertInertia(fn ($page) => $page
            ->where('grantable', fn (Collection $names) => $names->sort()->values()->all() === $this->delegate->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all()));
        $this->actingAs($this->admin)->get(route('acp.acl.roles.create'))->assertInertia(fn ($page) => $page->where('grantable', null));

        // The role the member has already is theirs to leave as it is.
        $this->actingAs($this->delegate->fresh())->get(route('acp.users.edit', $member))->assertInertia(fn ($page) => $page
            ->where('lockedRoles', ['admin', 'editor', 'finance', 'moderator']));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function giveRoles(User $member, array $roles, ?string $nickname = null): TestResponse
    {
        return $this->actingAs($this->delegate->fresh())->put(route('acp.users.update', $member), [
            'nickname' => $nickname ?? $member->nickname,
            'email' => $member->email,
            'roles' => $roles,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeRole(string $name, array $permissions): TestResponse
    {
        return $this->actingAs($this->delegate->fresh())->post(route('acp.acl.roles.store'), ['name' => $name, 'guard_name' => 'web', 'permissions' => $permissions]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function saveRole(string $name, array $permissions): TestResponse
    {
        return $this->actingAs($this->delegate->fresh())->put(route('acp.acl.roles.update', Role::findByName($name)), ['name' => $name, 'guard_name' => 'web', 'permissions' => $permissions]);
    }

    private function nameShaped(string $shape, int $id): string
    {
        $name = match ($shape) {
            'ulid of digits' => sprintf('%026d', $id),
            'ulid' => str_pad('0'.$id, 26, 'Z'),
            default => sprintf('%08d-0000-4000-8000-000000000000', $id),
        };
        $this->assertTrue(PermissionRegistrar::isUid($name), $name);

        return $name;
    }

    private function linkDiscord(User $staff, User $member): TestResponse
    {
        return $this->actingAs($staff->fresh())->post(route('acp.users.social-accounts.store', $member), ['provider' => 'discord', 'provider_id' => '424242']);
    }

    private function mapping(string $role, bool $fromDiscord, string $discordRole = '100'): void
    {
        DiscordRoleMapping::query()->create([
            'spatie_role_name' => $role,
            'discord_role_id' => $discordRole,
            'sync_to_discord' => true,
            'sync_from_discord' => $fromDiscord,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function rolesOf(User $user): array
    {
        return $user->fresh()->getRoleNames()->sort()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function permissionsOf(string $role): array
    {
        return Role::findByName($role)->permissions()->pluck('name')->sort()->values()->all();
    }
}
