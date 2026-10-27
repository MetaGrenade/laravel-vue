<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('editor');
        Role::findOrCreate('moderator');

        foreach (['view', 'edit', 'delete', 'verify', 'update', 'warn', 'ban'] as $ability) {
            Permission::findOrCreate('users.acp.'.$ability);
        }
    }

    #[Test]
    public function an_editor_cannot_open_or_change_a_member_without_a_user_permission(): void
    {
        $editor = User::factory()->create()->assignRole('editor');
        $member = User::factory()->unverified()->create();

        $this->actingAs($editor)->get(route('acp.users.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.users.edit', $member))->assertForbidden();
        $this->actingAs($editor)->put(route('acp.users.verify', $member))->assertForbidden();
        $this->actingAs($editor)->put(route('acp.users.update', $member), [
            'nickname' => 'Renamed member',
            'email' => $member->email,
            'roles' => [],
        ])->assertForbidden();

        $this->assertNull($member->fresh()->email_verified_at);
        $this->assertNotSame('Renamed member', $member->fresh()->nickname);
    }

    #[Test]
    public function a_moderator_can_open_the_list_and_cannot_edit_or_verify_an_account(): void
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $moderator->givePermissionTo(['users.acp.view', 'users.acp.warn', 'users.acp.ban']);
        $member = User::factory()->unverified()->create();

        $this->actingAs($moderator)->get(route('acp.users.index'))->assertOk();
        $this->actingAs($moderator)->get(route('acp.users.moderation.show', $member))->assertOk();
        $this->actingAs($moderator)->get(route('acp.users.edit', $member))->assertForbidden();
        $this->actingAs($moderator)->put(route('acp.users.verify', $member))->assertForbidden();

        $this->assertNull($member->fresh()->email_verified_at);
    }

    #[Test]
    public function edit_and_verify_each_allow_only_their_own_action(): void
    {
        $member = User::factory()->unverified()->create(['nickname' => 'Original name']);

        $verifier = User::factory()->create()->assignRole('editor');
        $verifier->givePermissionTo('users.acp.verify');

        $this->actingAs($verifier)->put(route('acp.users.verify', $member))->assertRedirect();
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->actingAs($verifier)->get(route('acp.users.edit', $member))->assertForbidden();

        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo('users.acp.edit');

        $this->actingAs($editor)->put(route('acp.users.update', $member), [
            'nickname' => 'Renamed member',
            'email' => $member->email,
            'roles' => [],
        ])->assertRedirect();

        $this->assertSame('Renamed member', $member->fresh()->nickname);
        $this->actingAs($editor)->get(route('acp.users.index'))->assertForbidden();
    }
}
