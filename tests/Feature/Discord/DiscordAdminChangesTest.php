<?php

namespace Tests\Feature\Discord;

use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use MetaForge\Discord\Models\DiscordRoleMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The companion package's staff routes (docs/discord-companion.md): looking needs system.acp.view, changing needs system.acp.edit, and
 * a mapping, which gives its role to whoever holds a Discord role, is theirs to change only if they could give that role themselves.
 * The staff roles are an administrator's to map.
 */
class DiscordAdminChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();

        // One role anybody here holds all of (an editor has polls.acp.view), and one they do not.
        Role::findOrCreate('helper')->syncPermissions(['polls.acp.view']);
        Role::findOrCreate('finance')->syncPermissions(['billing.acp.view']);
    }

    #[Test]
    public function looking_does_not_let_somebody_change_anything(): void
    {
        $viewer = $this->staffWith('system.acp.view');
        $mapping = $this->mapping('helper');

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RegisteredRoute $route) => str_starts_with($route->uri(), 'discord/admin/'))
            ->flatMap(fn (RegisteredRoute $route) => collect($route->methods())
                ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
                ->map(fn (string $method) => [$method, strtr($route->uri(), ['{roleMapping}' => (string) $mapping->id, '{userId}' => (string) $viewer->id])]));

        // The three changes to a mapping, a member's sync and the retry of what failed, and whatever the package adds.
        $this->assertGreaterThanOrEqual(5, $writes->count());

        foreach ($writes as [$method, $uri]) {
            $this->actingAs($viewer)
                ->json($method, '/'.$uri, ['spatie_role_name' => 'helper', 'discord_role_id' => '1', 'sync_from_discord' => true])
                ->assertForbidden();
        }

        $this->assertSame([['helper', '100', false]], $this->mappings());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function looking_still_opens_the_lists(): void
    {
        $viewer = $this->staffWith('system.acp.view');
        $this->mapping('editor');

        foreach (['role-mappings', 'sync/status', 'sync/runs', 'sync/failures'] as $list) {
            $this->actingAs($viewer)->getJson("/discord/admin/{$list}")->assertOk();
        }
    }

    #[Test]
    public function editing_changes_the_mappings_of_a_role_the_editor_could_give(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');

        $id = $this->actingAs($editor)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'helper', 'discord_role_id' => '200'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$id}", ['sync_from_discord' => true])->assertOk();
        $this->assertSame([['helper', '200', true]], $this->mappings());

        $this->actingAs($editor)->deleteJson("/discord/admin/role-mappings/{$id}")->assertNoContent();
        $this->assertSame([], $this->mappings());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return [
            'admin as stored' => ['admin'],
            'admin in capitals' => ['ADMIN'],
            'admin with one capital' => ['Admin'],
            'editor' => ['editor'],
            'moderator with one capital' => ['Moderator'],
        ];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function editing_does_not_map_a_staff_role(string $spelling): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $other = $this->mapping('helper');

        $this->refused($this->actingAs($editor)->postJson('/discord/admin/role-mappings', ['spatie_role_name' => $spelling, 'discord_role_id' => '300']));
        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$other->id}", ['spatie_role_name' => $spelling]));

        $this->assertSame([['helper', '100', false]], $this->mappings());
    }

    #[Test]
    #[DataProvider('spellings')]
    public function editing_does_not_touch_a_mapping_of_a_staff_role(string $stored): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $mapping = $this->mapping($stored);

        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$mapping->id}", ['sync_from_discord' => true]));
        // Pointing it at another role would take the administrator's own mapping away.
        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$mapping->id}", ['spatie_role_name' => 'helper']));
        $this->refused($this->actingAs($editor)->deleteJson("/discord/admin/role-mappings/{$mapping->id}"));

        $this->assertSame([[$stored, '100', false]], $this->mappings());
    }

    /**
     * MariaDB and MySQL find "admin" under these too, so they are refused there. SQLite and PostgreSQL find no role by them, and a
     * mapping that names no role gives none.
     */
    #[Test]
    #[TestWith(['ádmin'])]
    #[TestWith(["ad\u{200B}min"])]
    public function editing_stores_no_name_that_the_database_reads_as_the_administrator_role(string $spelling): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');

        $this->actingAs($editor)->postJson('/discord/admin/role-mappings', ['spatie_role_name' => $spelling, 'discord_role_id' => '300']);

        $administrators = DiscordRoleMapping::query()->pluck('spatie_role_name')
            ->filter(fn (string $stored) => Role::query()->where('name', $stored)->value('name') === 'admin');

        $this->assertSame([], $administrators->values()->all());
    }

    #[Test]
    public function editing_does_not_map_a_role_with_a_permission_the_editor_does_not_hold(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $own = $this->mapping('helper');
        $theirs = $this->mapping('finance', '101');
        $notHeld = ['message' => 'You can only give permissions you hold yourself.'];

        $this->actingAs($editor)->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'finance', 'discord_role_id' => '300'])->assertForbidden()->assertJson($notHeld);
        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$own->id}", ['spatie_role_name' => 'finance'])->assertForbidden()->assertJson($notHeld);
        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$theirs->id}", ['sync_from_discord' => true])->assertForbidden()->assertJson($notHeld);
        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$theirs->id}", ['spatie_role_name' => 'helper'])->assertForbidden()->assertJson($notHeld);
        $this->actingAs($editor)->deleteJson("/discord/admin/role-mappings/{$theirs->id}")->assertForbidden()->assertJson($notHeld);

        $this->assertSame([['helper', '100', false], ['finance', '101', false]], $this->mappings());

        // Holding what the role holds is what opens it.
        $editor->givePermissionTo('billing.acp.view');

        $this->actingAs($editor->fresh())->putJson("/discord/admin/role-mappings/{$theirs->id}", ['sync_from_discord' => true])->assertOk();
    }

    #[Test]
    public function editing_does_not_map_a_role_that_does_not_exist_but_tidies_its_mapping_away(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $own = $this->mapping('helper');
        $stale = $this->mapping('gone', '101');
        $missing = ['message' => 'Only an administrator can map a role that does not exist.'];

        // It could be made later, with anything on it.
        $this->actingAs($editor)->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'later', 'discord_role_id' => '300'])->assertForbidden()->assertJson($missing);
        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$own->id}", ['spatie_role_name' => 'later'])->assertForbidden()->assertJson($missing);
        // Nor is one that is there already pointed at another Discord role, to wait for the role to come back.
        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$stale->id}", ['discord_role_id' => '999', 'sync_from_discord' => true])->assertForbidden()->assertJson($missing);
        $this->assertSame([['helper', '100', false], ['gone', '101', false]], $this->mappings());
        $this->actingAs($editor)->deleteJson("/discord/admin/role-mappings/{$stale->id}")->assertNoContent();

        $this->assertSame([['helper', '100', false]], $this->mappings());
    }

    /** Syncing from Discord also takes the role from a linked account that lacks the Discord role. */
    #[Test]
    public function editing_does_not_set_discord_to_take_a_role_somebody_has(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $given = $this->mapping('helper');
        User::factory()->create()->assignRole('helper');
        $actor = fn () => $this->actingAs($editor->fresh());
        $taking = ['message' => 'Syncing from Discord takes a role from accounts without the Discord role. Somebody has this one, so ask an administrator.'];

        $actor()->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'helper', 'discord_role_id' => '300', 'sync_from_discord' => true])->assertForbidden()->assertJson($taking);
        $actor()->putJson("/discord/admin/role-mappings/{$given->id}", ['sync_from_discord' => true])->assertForbidden()->assertJson($taking);
        $this->assertSame([['helper', '100', false]], $this->mappings());

        // Sent to Discord only, it takes nothing.
        $actor()->putJson("/discord/admin/role-mappings/{$given->id}", ['sync_to_discord' => false])->assertOk();
        Role::findOrCreate('quiz')->syncPermissions(['polls.acp.view']);
        User::factory()->create()->assignRole('quiz');
        $actor()->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'quiz', 'discord_role_id' => '301'])->assertCreated();
    }

    #[Test]
    public function a_mapping_that_already_takes_a_role_is_not_pointed_elsewhere_but_can_be_stopped(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        Role::findOrCreate('quiz')->syncPermissions(['polls.acp.view']);
        $inbound = $this->mapping('helper');
        $inbound->update(['sync_from_discord' => true]);
        User::factory()->create()->assignRole('helper', 'quiz');
        $actor = fn () => $this->actingAs($editor->fresh());
        $address = "/discord/admin/role-mappings/{$inbound->id}";

        $actor()->putJson($address, ['discord_role_id' => '999'])->assertForbidden();
        $actor()->putJson($address, ['spatie_role_name' => 'quiz'])->assertForbidden();
        $this->assertSame([['helper', '100', true]], $this->mappings());

        // What changes nothing about who it takes from is theirs, and so is switching it off or removing it.
        $actor()->putJson($address, ['sync_to_discord' => false])->assertOk();
        $actor()->putJson($address, ['discord_role_id' => '100', 'spatie_role_name' => 'helper', 'sync_from_discord' => true])->assertOk();
        $actor()->putJson($address, ['sync_from_discord' => false])->assertOk();
        $inbound->update(['sync_from_discord' => true]);
        $actor()->deleteJson($address)->assertNoContent();
    }

    #[Test]
    public function an_administrator_sets_discord_to_take_a_role_somebody_has(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        User::factory()->create()->assignRole('helper');

        $this->actingAs($admin)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'helper', 'discord_role_id' => '300', 'sync_from_discord' => true])
            ->assertCreated();
    }

    /** The bot gives a mapping's role by its name, and Spatie reads a name shaped like an id as a role's number. */
    #[Test]
    public function nobody_maps_a_role_whose_name_would_be_read_as_a_number(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        $name = sprintf('%026d', Role::findByName('admin')->id);
        Role::query()->create(['name' => $name, 'guard_name' => 'web']);
        $own = $this->mapping('helper', '101');

        foreach ([$admin, $this->staffWith('system.acp.view', 'system.acp.edit')] as $somebody) {
            $actor = fn () => $this->actingAs($somebody->fresh());
            $actor()->postJson('/discord/admin/role-mappings', ['spatie_role_name' => $name, 'discord_role_id' => '300'])->assertUnprocessable()->assertJsonValidationErrors('spatie_role_name');
            $actor()->putJson("/discord/admin/role-mappings/{$own->id}", ['spatie_role_name' => $name])->assertUnprocessable();
        }
        $this->assertSame([['helper', '101', false]], $this->mappings());

        // One made before is not switched on, and can be removed.
        $before = $this->mapping($name);
        $this->actingAs($admin)->putJson("/discord/admin/role-mappings/{$before->id}", ['sync_from_discord' => true])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson("/discord/admin/role-mappings/{$before->id}")->assertNoContent();
    }

    #[Test]
    public function an_administrator_changes_the_mapping_of_the_administrator_role(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $id = $this->actingAs($admin)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'admin', 'discord_role_id' => '400'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)->putJson("/discord/admin/role-mappings/{$id}", ['sync_from_discord' => true])->assertOk();
        $this->actingAs($admin)->deleteJson("/discord/admin/role-mappings/{$id}")->assertNoContent();
    }

    #[Test]
    public function somebody_with_the_view_permission_cannot_make_themselves_an_administrator_through_discord(): void
    {
        config(['discord.guild_id' => '555', 'discord.bot_shared_secret' => 'shared-secret', 'discord.inbound_sync_queue' => false]);
        $viewer = $this->staffWith('system.acp.view');
        SocialAccount::query()->create(['user_id' => $viewer->id, 'provider' => 'discord', 'provider_id' => '123456']);

        $this->actingAs($viewer)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'admin', 'discord_role_id' => '777', 'sync_from_discord' => true])
            ->assertForbidden();

        // The bot passing on that they now hold the Discord role changes nothing: nothing maps it.
        $body = (string) json_encode(['type' => 'guild.member.update', 'payload' => ['discord_user_id' => '123456', 'guild_id' => '555', 'roles' => ['777']]]);
        $time = (string) time();

        $this->call('POST', '/discord/bot/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DISCORD_BOT_TIMESTAMP' => $time,
            'HTTP_X_DISCORD_BOT_SIGNATURE' => hash_hmac('sha256', "{$time}.{$body}", 'shared-secret'),
        ], content: $body)->assertOk();

        $this->assertFalse($viewer->fresh()->hasRole('admin'));
    }

    #[Test]
    public function the_mappings_page_offers_the_roles_the_person_could_give(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $this->actingAs($this->staffWith('system.acp.view', 'system.acp.edit'))
            ->get(route('acp.discord.role-mappings'))
            ->assertInertia(fn ($page) => $page
                ->where('spatieRoles', ['helper', 'user'])
                ->where('lockedRoles', ['admin', 'editor', 'finance', 'moderator']));

        $this->actingAs($admin)
            ->get(route('acp.discord.role-mappings'))
            ->assertInertia(fn ($page) => $page
                ->where('spatieRoles', ['admin', 'editor', 'finance', 'helper', 'moderator', 'user'])
                ->where('lockedRoles', []));
    }

    /**
     * The access control page lets an administrator take permissions off the administrator role. The server still allows them
     * everything, so each page is told what it allows: the permission names the browser is given do not say so.
     */
    #[Test]
    public function the_pages_say_who_the_server_lets_make_changes(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        Role::findByName('admin')->syncPermissions([]);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $staff = [
            [$admin, true],
            [$this->staffWith('system.acp.view', 'system.acp.edit'), true],
            [$this->staffWith('system.acp.view'), false],
        ];

        foreach (['acp.discord.role-mappings', 'acp.discord.sync'] as $page) {
            foreach ($staff as [$who, $allowed]) {
                $this->actingAs($who)->get(route($page))->assertInertia(fn ($inertia) => $inertia->where('canEdit', $allowed));
            }
        }

        $this->actingAs($admin)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'editor', 'discord_role_id' => '200'])
            ->assertCreated();
    }

    private function staffWith(string ...$permissions): User
    {
        $staff = User::factory()->create();
        $staff->syncRoles(['editor']);
        $staff->givePermissionTo($permissions);

        return $staff->fresh();
    }

    private function mapping(string $role, string $discordRole = '100'): DiscordRoleMapping
    {
        return DiscordRoleMapping::query()->create([
            'spatie_role_name' => $role,
            'discord_role_id' => $discordRole,
            'sync_to_discord' => true,
            'sync_from_discord' => false,
        ]);
    }

    /**
     * @return list<array{string, string, bool}>
     */
    private function mappings(): array
    {
        return DiscordRoleMapping::query()->orderBy('id')->get()
            ->map(fn (DiscordRoleMapping $mapping) => [$mapping->spatie_role_name, $mapping->discord_role_id, $mapping->sync_from_discord])
            ->all();
    }

    private function refused(TestResponse $response): void
    {
        $response->assertForbidden()->assertJson(['message' => 'Only an administrator can change what the admin, editor and moderator roles are mapped to.']);
    }
}
