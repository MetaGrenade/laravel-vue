<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('editor');
        Role::findOrCreate('moderator');

        foreach (['acl', 'forums', 'reputation', 'tokens', 'trust_safety', 'search'] as $area) {
            foreach (['view', 'create', 'edit', 'delete'] as $ability) {
                Permission::findOrCreate($area.'.acp.'.$ability);
            }
        }

        foreach (['lock', 'publish', 'move', 'review', 'automod'] as $ability) {
            Permission::findOrCreate('forums.acp.'.$ability);
        }
    }

    #[Test]
    public function an_editor_can_open_the_dashboard_and_cannot_open_the_other_areas_without_a_permission(): void
    {
        $editor = User::factory()->create()->assignRole('editor');

        $this->actingAs($editor)->get(route('acp.dashboard'))->assertOk();
        $this->actingAs($editor)->get(route('acp.acl.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.forums.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.forums.reports.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.forums.automod.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.reputation.badges.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.tokens.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.trust-safety.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.search-analytics.index'))->assertForbidden();
        $this->actingAs($editor)->delete(route('acp.acl.roles.destroy', Role::findOrCreate('temporary')))->assertForbidden();

        $this->assertNotNull(Role::findByName('temporary'));
    }

    #[Test]
    public function each_area_opens_for_its_own_view_permission_and_a_delete_needs_delete(): void
    {
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo([
            'acl.acp.view',
            'reputation.acp.view',
            'tokens.acp.view',
            'trust_safety.acp.view',
            'forums.acp.view',
        ]);

        $this->actingAs($viewer)->get(route('acp.acl.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.reputation.badges.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.tokens.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.trust-safety.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.forums.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.forums.reports.index'))->assertOk();
        $this->actingAs($viewer)->post(route('acp.reputation.badges.store'), [
            'name' => 'Founder',
            'points_required' => 10,
        ])->assertForbidden();

        $role = Role::findOrCreate('temporary');
        $this->actingAs($viewer)->delete(route('acp.acl.roles.destroy', $role))->assertForbidden();
        $this->assertNotNull(Role::findByName('temporary'));

        $deleter = User::factory()->create()->assignRole('editor');
        $deleter->givePermissionTo('acl.acp.delete');
        $this->actingAs($deleter)->delete(route('acp.acl.roles.destroy', $role))->assertRedirect();
        $this->assertNull(Role::query()->where('name', 'temporary')->where('guard_name', 'web')->first());
    }

    #[Test]
    public function a_token_can_be_deleted_only_with_the_delete_permission(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('Integration')->accessToken;

        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('tokens.acp.view');

        $this->actingAs($viewer)->delete(route('acp.tokens.destroy', $token))->assertForbidden();
        $this->assertNotNull($token->fresh());

        $deleter = User::factory()->create()->assignRole('editor');
        $deleter->givePermissionTo('tokens.acp.delete');

        $this->actingAs($deleter)->delete(route('acp.tokens.destroy', $token))->assertRedirect();
        $this->assertNull($token->fresh());
    }
}
