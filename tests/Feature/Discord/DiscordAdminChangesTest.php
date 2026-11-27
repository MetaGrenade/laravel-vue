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
 * a mapping of the administrator role, which gives that role to whoever holds a Discord role, is an administrator's to change.
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
    }

    #[Test]
    public function looking_does_not_let_somebody_change_anything(): void
    {
        $viewer = $this->staffWith('system.acp.view');
        $mapping = $this->mapping('editor');

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RegisteredRoute $route) => str_starts_with($route->uri(), 'discord/admin/'))
            ->flatMap(fn (RegisteredRoute $route) => collect($route->methods())
                ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
                ->map(fn (string $method) => [$method, strtr($route->uri(), ['{roleMapping}' => (string) $mapping->id, '{userId}' => (string) $viewer->id])]));

        // The three changes to a mapping, a member's sync and the retry of what failed, and whatever the package adds.
        $this->assertGreaterThanOrEqual(5, $writes->count());

        foreach ($writes as [$method, $uri]) {
            $this->actingAs($viewer)
                ->json($method, '/'.$uri, ['spatie_role_name' => 'editor', 'discord_role_id' => '1', 'sync_from_discord' => true])
                ->assertForbidden();
        }

        $this->assertSame([['editor', '100', false]], $this->mappings());
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
    public function editing_changes_the_mappings_of_other_roles(): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');

        $id = $this->actingAs($editor)
            ->postJson('/discord/admin/role-mappings', ['spatie_role_name' => 'editor', 'discord_role_id' => '200'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$id}", ['sync_from_discord' => true])->assertOk();
        $this->assertSame([['editor', '200', true]], $this->mappings());

        $this->actingAs($editor)->deleteJson("/discord/admin/role-mappings/{$id}")->assertNoContent();
        $this->assertSame([], $this->mappings());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return ['as stored' => ['admin'], 'capitals' => ['ADMIN'], 'one capital' => ['Admin']];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function editing_does_not_map_the_administrator_role(string $spelling): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $other = $this->mapping('editor');

        $this->refused($this->actingAs($editor)->postJson('/discord/admin/role-mappings', ['spatie_role_name' => $spelling, 'discord_role_id' => '300']));
        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$other->id}", ['spatie_role_name' => $spelling]));

        $this->assertSame([['editor', '100', false]], $this->mappings());
    }

    #[Test]
    #[DataProvider('spellings')]
    public function editing_does_not_touch_a_mapping_of_the_administrator_role(string $stored): void
    {
        $editor = $this->staffWith('system.acp.view', 'system.acp.edit');
        $mapping = $this->mapping($stored);

        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$mapping->id}", ['sync_from_discord' => true]));
        // Pointing it at another role would take the administrator's own mapping away.
        $this->refused($this->actingAs($editor)->putJson("/discord/admin/role-mappings/{$mapping->id}", ['spatie_role_name' => 'editor']));
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
    public function the_mappings_page_offers_the_administrator_role_to_an_administrator_only(): void
    {
        config(['inertia.testing.ensure_pages_exist' => true]);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $this->actingAs($this->staffWith('system.acp.view', 'system.acp.edit'))
            ->get(route('acp.discord.role-mappings'))
            ->assertInertia(fn ($page) => $page
                ->where('spatieRoles', ['editor', 'moderator', 'user'])
                ->where('lockedRoles', ['admin']));

        $this->actingAs($admin)
            ->get(route('acp.discord.role-mappings'))
            ->assertInertia(fn ($page) => $page
                ->where('spatieRoles', ['admin', 'editor', 'moderator', 'user'])
                ->where('lockedRoles', []));
    }

    private function staffWith(string ...$permissions): User
    {
        $staff = User::factory()->create();
        $staff->syncRoles(['editor']);
        $staff->givePermissionTo($permissions);

        return $staff->fresh();
    }

    private function mapping(string $role): DiscordRoleMapping
    {
        return DiscordRoleMapping::query()->create([
            'spatie_role_name' => $role,
            'discord_role_id' => '100',
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
        $response->assertForbidden()->assertJson(['message' => 'Only an administrator can change what the administrator role is mapped to.']);
    }
}
