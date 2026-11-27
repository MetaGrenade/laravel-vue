<?php

namespace Tests\Feature\Admin;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Security\Staff;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A staff account is an administrator's to change (docs/acp-permissions.md): one with the admin, editor or moderator role, or with any
 * permission of the admin area. Somebody holding every permission, without the administrator role, changes nothing about it.
 */
class StaffAccountProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'Only an administrator can change a staff account.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->admin = User::factory()->create();
        $this->admin->syncRoles(['admin']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffAccounts(): array
    {
        return [
            'an editor' => ['editor'],
            'a moderator' => ['moderator'],
            'a role of their own that opens an admin page' => ['role'],
            'a permission of their own' => ['permission'],
            'a staff role that holds nothing' => ['empty staff role'],
        ];
    }

    #[Test]
    #[DataProvider('staffAccounts')]
    public function somebody_holding_every_permission_changes_nothing_about_a_staff_account(string $kind): void
    {
        $holder = $this->holder();
        $account = $this->staffAccount($kind);
        $account->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $linked = SocialAccount::query()->create(['user_id' => $account->id, 'provider' => 'google', 'provider_id' => 'their-own']);
        $key = $account->createToken('Their key', ['profile:read'])->accessToken;
        $before = $this->snapshot($account);

        $refusedAsTheirAccount = [
            ['put', route('acp.users.update', $account), ['nickname' => 'taken', 'email' => 'taken@example.test', 'roles' => []]],
            ['delete', route('acp.users.destroy', $account), []],
            ['put', route('acp.users.verify', $account), []],
            ['post', route('acp.users.social-accounts.store', $account), ['provider' => 'discord', 'provider_id' => 'the-holders-own']],
            ['delete', route('acp.users.social-accounts.destroy', [$account, $linked]), ['force' => true]],
            ['post', route('acp.tokens.store'), ['name' => 'Automation', 'abilities' => ['forum:moderate'], 'user_id' => $account->id]],
            ['put', route('acp.tokens.update', $key), ['name' => 'Taken', 'abilities' => ['forum:moderate']]],
            ['patch', route('acp.tokens.revoke', $key), []],
            ['delete', route('acp.tokens.destroy', $key), []],
        ];

        foreach ($refusedAsTheirAccount as [$method, $address, $form]) {
            $this->actingAs($holder->fresh())->{$method}($address, $form)->assertForbidden();
            $this->assertSame($before, $this->snapshot($account), "{$method} {$address}");
        }

        // These say their own rule, which covers all staff.
        $reason = ['reason' => 'Because I say so.'];
        $this->actingAs($holder)->post(route('acp.users.warn', $account), $reason)->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');
        $this->actingAs($holder)->put(route('acp.users.ban', $account), $reason)->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');
        $this->actingAs($holder)->put(route('acp.users.unban', $account))->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');
        $this->actingAs($holder)->post(route('acp.users.cleanup', $account), $reason)->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');
        $this->actingAs($holder)->delete(route('acp.users.two-factor.destroy', $account), $reason)
            ->assertSessionHasErrors(['two_factor' => 'Only an administrator can reset two-factor for a member of staff.']);
        $this->actingAs($holder)->post(route('acp.users.impersonate', $account), $reason)
            ->assertSessionHasErrors(['impersonate' => 'Staff accounts cannot be viewed as. Only customers can.']);

        $this->assertSame($before, $this->snapshot($account));
        $this->assertAuthenticatedAs($holder);
    }

    #[Test]
    #[DataProvider('staffAccounts')]
    public function an_administrator_changes_a_staff_account(string $kind): void
    {
        $account = $this->staffAccount($kind);
        $key = $account->createToken('Their key', ['profile:read'])->accessToken;
        $form = ['nickname' => 'renamed', 'email' => $account->email, 'roles' => $account->getRoleNames()->all()];

        $this->actingAs($this->admin)->put(route('acp.users.update', $account), $form)->assertRedirect(route('acp.users.index'));
        $this->actingAs($this->admin)->put(route('acp.users.verify', $account))->assertRedirect(route('acp.users.index'));
        $this->actingAs($this->admin)->post(route('acp.users.social-accounts.store', $account), ['provider' => 'discord', 'provider_id' => '1'])->assertRedirect(route('acp.users.edit', $account));
        $this->actingAs($this->admin)->patch(route('acp.tokens.revoke', $key))->assertRedirect(route('acp.tokens.index'));
        $this->actingAs($this->admin)->put(route('acp.users.ban', $account), ['reason' => 'Because I say so.'])->assertSessionHas('success');

        $this->assertSame('renamed', $account->fresh()->nickname);
        $this->assertNotNull($account->fresh()->email_verified_at);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertTrue((bool) $account->fresh()->is_banned);

        $this->actingAs($this->admin)->delete(route('acp.users.destroy', $account))->assertRedirect(route('acp.users.index'));
        $this->assertModelMissing($account);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function memberAccounts(): array
    {
        return [
            'no role' => ['none'],
            'the user role' => ['user'],
            'a role with no permissions' => ['empty role'],
            'a permission outside the admin area' => ['other permission'],
        ];
    }

    #[Test]
    #[DataProvider('memberAccounts')]
    public function the_same_permissions_work_on_an_account_that_is_not_staff(string $kind): void
    {
        $holder = $this->holder();
        $member = User::factory()->create(['email_verified_at' => null]);
        Permission::findOrCreate('clubhouse.enter');

        match ($kind) {
            'user' => $member->assignRole('user'),
            'empty role' => $member->assignRole(Role::findOrCreate('supporter')),
            'other permission' => $member->givePermissionTo('clubhouse.enter'),
            default => null,
        };

        // Somebody holding every permission holds this one too.
        $holder->givePermissionTo('clubhouse.enter');
        $holder = $holder->fresh();
        $form = ['nickname' => 'renamed', 'email' => $member->email, 'roles' => $member->getRoleNames()->all()];

        $this->actingAs($holder)->put(route('acp.users.update', $member), $form)->assertRedirect(route('acp.users.index'));
        $this->actingAs($holder)->put(route('acp.users.verify', $member))->assertRedirect(route('acp.users.index'));
        $this->actingAs($holder)->post(route('acp.users.social-accounts.store', $member), ['provider' => 'discord', 'provider_id' => '2'])->assertRedirect(route('acp.users.edit', $member));
        $this->actingAs($holder)->post(route('acp.tokens.store'), ['name' => 'Automation', 'abilities' => ['profile:read'], 'user_id' => $member->id])->assertSessionHas('plain_text_token');
        $this->actingAs($holder)->put(route('acp.users.ban', $member), ['reason' => 'Because I say so.'])->assertSessionHas('success');

        $this->assertSame('renamed', $member->fresh()->nickname);
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertTrue((bool) $member->fresh()->is_banned);
    }

    #[Test]
    public function somebody_cannot_change_their_own_account_from_the_admin_area(): void
    {
        $holder = $this->holder();
        $form = ['nickname' => 'more', 'email' => $holder->email, 'roles' => ['editor', 'moderator']];

        $this->actingAs($holder)->put(route('acp.users.update', $holder), $form)->assertForbidden();
        $this->actingAs($holder->fresh())->post(route('acp.tokens.store'), ['name' => 'Mine', 'abilities' => ['profile:read'], 'user_id' => $holder->id])->assertForbidden();

        $this->assertSame(['editor'], $holder->fresh()->getRoleNames()->all());
        $this->assertSame(0, $holder->tokens()->count());
    }

    #[Test]
    public function a_bulk_action_leaves_staff_and_does_the_rest(): void
    {
        $holder = $this->holder();
        $staff = $this->staffAccount('permission');
        $staff->forceFill(['nickname' => 'helper'])->save();
        $member = User::factory()->create(['email_verified_at' => null]);
        $ids = [$staff->id, $member->id];

        $this->actingAs($holder)->patch(route('acp.users.bulk-update'), ['action' => 'verify', 'ids' => $ids])
            ->assertSessionHas('success', 'Verified 1 user.')
            ->assertSessionHas('error', 'Not verified: helper ('.self::REFUSAL.').');
        $this->assertNull($staff->fresh()->email_verified_at);

        $this->actingAs($holder->fresh())->patch(route('acp.users.bulk-update'), ['action' => 'delete', 'ids' => $ids])
            ->assertSessionHas('success', 'Deleted 1 user.')
            ->assertSessionHas('error', 'Not deleted: helper ('.self::REFUSAL.').');
        $this->assertModelExists($staff);
        $this->assertModelMissing($member);
    }

    #[Test]
    public function the_member_list_and_the_edit_page_say_which_accounts_are_an_administrators(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        $holder = $this->holder();
        $staff = $this->staffAccount('role');
        $member = User::factory()->create();
        $expected = collect([$this->admin->id => true, $holder->id => true, $staff->id => true, $member->id => false])->sortKeys()->all();
        $locked = fn (Collection $users) => $users->pluck('locked', 'id')->sortKeys()->all() === $expected;

        $this->actingAs($holder)->get(route('acp.users.index'))->assertInertia(fn ($page) => $page->where('users.data', $locked));
        $this->actingAs($this->admin)->get(route('acp.users.index'))
            ->assertInertia(fn ($page) => $page->where('users.data', fn (Collection $users) => $users->pluck('locked')->unique()->values()->all() === [false]));

        $this->actingAs($holder->fresh())->get(route('acp.users.edit', $staff))
            ->assertInertia(fn ($page) => $page->where('locked', self::REFUSAL));
        $this->actingAs($holder->fresh())->get(route('acp.users.edit', $member))
            ->assertInertia(fn ($page) => $page->where('locked', null)->where('lockedRoles', ['admin', 'editor', 'moderator']));
        $this->actingAs($this->admin)->get(route('acp.users.edit', $staff))
            ->assertInertia(fn ($page) => $page->where('locked', null)->where('lockedRoles', []));
    }

    #[Test]
    public function the_list_of_keys_says_which_are_an_administrators(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        $holder = $this->holder();
        $staffKey = $this->staffAccount('permission')->createToken('Staff', ['profile:read'])->accessToken;
        $memberKey = User::factory()->create()->createToken('Member', ['profile:read'])->accessToken;
        $expected = [$staffKey->id => true, $memberKey->id => false];

        $this->actingAs($holder)->get(route('acp.tokens.index'))
            ->assertInertia(fn ($page) => $page->where('tokens.data', fn (Collection $keys) => $keys->pluck('locked', 'id')->sortKeys()->all() === $expected));
        $this->actingAs($this->admin)->get(route('acp.tokens.index'))
            ->assertInertia(fn ($page) => $page->where('tokens.data', fn (Collection $keys) => $keys->pluck('locked')->unique()->values()->all() === [false]));
    }

    #[Test]
    public function a_view_of_a_member_ends_when_they_are_given_an_admin_permission(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin)->post(route('acp.users.impersonate', $member), ['reason' => 'Looking into a ticket.'])->assertSessionHas(Impersonation::SESSION_KEY);

        $member->givePermissionTo('billing.acp.view');

        $this->get(route('dashboard'))->assertRedirect(route('acp.users.edit', $member))->assertSessionMissing(Impersonation::SESSION_KEY);
    }

    #[Test]
    public function the_command_says_when_the_account_it_is_given_is_staff(): void
    {
        $staff = $this->staffAccount('permission');
        $staff->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('metaforger:two-factor:reset', ['user' => (string) $staff->id, '--reason' => 'Ticket 123', '--force' => true])
            ->expectsOutputToContain('This is a member of staff.')
            ->assertSuccessful();
    }

    /** Asked about one of a list that was read without them, it reads them itself: the tests refuse a relation read lazily. */
    #[Test]
    public function an_account_out_of_a_list_can_be_asked_about(): void
    {
        $this->staffAccount('permission');
        User::factory()->create();

        $listed = User::query()->orderByDesc('id')->get();

        $this->assertSame([false, true, true], $listed->map(fn (User $account) => Staff::is($account))->all());
    }

    /** What says who is staff is read for a whole list at once, not once for each account in it. */
    #[Test]
    #[TestWith(['members'])]
    #[TestWith(['keys'])]
    #[TestWith(['bulk action'])]
    public function permissions_are_read_no_more_often_for_more_accounts(string $what): void
    {
        $holder = $this->holder();
        $reads = function (int $accounts) use ($holder, $what): int {
            $members = User::factory()->unverified()->count($accounts)->create();
            $members->each(fn (User $member) => $member->createToken('Key', ['profile:read']));
            $this->actingAs($holder->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();

            match ($what) {
                'members' => $this->get(route('acp.users.index'))->assertOk(),
                'keys' => $this->get(route('acp.tokens.index'))->assertOk(),
                default => $this->patch(route('acp.users.bulk-update'), ['action' => 'verify', 'ids' => $members->modelKeys()])->assertSessionHas('success'),
            };

            DB::disableQueryLog();

            return collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'model_has_permissions'))->count();
        };

        $this->assertSame($reads(2), $reads(5));
    }

    /** Somebody with the editor role and every permission there is, but not the administrator role. */
    private function holder(): User
    {
        $holder = User::factory()->create();
        $holder->syncRoles(['editor']);
        $holder->givePermissionTo(Permission::query()->get());

        return $holder->fresh();
    }

    private function staffAccount(string $kind): User
    {
        $account = User::factory()->create(['email_verified_at' => null]);

        match ($kind) {
            'role' => $account->assignRole(tap(Role::findOrCreate('bookkeeper'))->givePermissionTo('billing.acp.view')),
            'permission' => $account->givePermissionTo('billing.acp.view'),
            'empty staff role' => $account->assignRole(tap(Role::findByName('moderator'))->syncPermissions([])),
            default => $account->assignRole($kind),
        };

        return $account->fresh();
    }

    /**
     * Everything about an account that somebody taking it over would change.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $user = User::query()->with(['roles', 'permissions', 'socialAccounts', 'tokens'])->findOrFail($user->id);

        return [
            'account' => collect($user->getAttributes())->except(['updated_at', 'last_activity_at'])->all(),
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'permissions' => $user->permissions->pluck('name')->sort()->values()->all(),
            'sign-ins' => $user->socialAccounts->map(fn (SocialAccount $account) => "{$account->provider}:{$account->provider_id}")->sort()->values()->all(),
            'keys' => $user->tokens->map(fn ($key) => collect($key->getAttributes())->except(['updated_at'])->all())->values()->all(),
            'warnings and bans' => $user->moderationActions()->count(),
        ];
    }
}
