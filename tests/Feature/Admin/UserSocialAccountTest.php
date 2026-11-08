<?php

namespace Tests\Feature\Admin;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff disconnecting a provider from a member. Taking away the only way in is refused with the reason until the request says `force`
 * (the page's "Disconnect anyway"), because staff do need to cut off an account that is not the member's, but never by accident.
 * (The admin role passes every check, so these use the editor role.)
 */
class UserSocialAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'editor', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.update', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.ban', 'guard_name' => 'web']);
    }

    private function editorWith(string ...$permissions): User
    {
        $editor = User::factory()->create();
        $editor->assignRole('editor');
        $editor->givePermissionTo($permissions);

        return $editor;
    }

    private function link(User $member, string $provider): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $member->id,
            'provider' => $provider,
            'provider_id' => Str::uuid()->toString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function detach(User $staff, User $member, SocialAccount $account, array $data = []): TestResponse
    {
        return $this->actingAs($staff)->delete(route('acp.users.social-accounts.destroy', [$member, $account]), $data);
    }

    #[Test]
    public function the_only_way_a_member_has_to_sign_in_is_refused_with_the_reason(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'steam_1@oauth.local']);
        $account = $this->link($member, 'steam');

        $this->detach($this->editorWith('users.acp.update'), $member, $account)
            ->assertRedirect()
            ->assertSessionHasErrors('social_account');

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
        $this->assertStringContainsString('only way this member has to sign in', session('errors')->first('social_account'));
    }

    #[Test]
    public function force_takes_it_away_anyway(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'steam_1@oauth.local']);
        $account = $this->link($member, 'steam');

        $this->detach($this->editorWith('users.acp.update'), $member, $account, ['force' => true])
            ->assertRedirect(route('acp.users.edit', $member))
            ->assertSessionHas('success', 'Social account detached.')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    #[Test]
    public function a_member_with_a_verified_address_needs_no_force(): void
    {
        $member = User::factory()->create();
        $account = $this->link($member, 'google');

        $this->detach($this->editorWith('users.acp.update'), $member, $account)
            ->assertRedirect(route('acp.users.edit', $member))
            ->assertSessionHas('success', 'Social account detached.');

        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    #[Test]
    public function a_member_with_another_provider_needs_no_force(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'steam_1@oauth.local']);
        $steam = $this->link($member, 'steam');
        $this->link($member, 'discord');

        $this->detach($this->editorWith('users.acp.update'), $member, $steam)
            ->assertSessionHas('success', 'Social account detached.');

        $this->assertDatabaseMissing('social_accounts', ['id' => $steam->id]);
    }

    #[Test]
    public function the_update_permission_is_still_required_with_or_without_force(): void
    {
        $member = User::factory()->create();
        $account = $this->link($member, 'google');
        $staff = $this->editorWith('users.acp.ban');

        $this->detach($staff, $member, $account)->assertForbidden();
        $this->detach($staff, $member, $account, ['force' => true])->assertForbidden();

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
    }

    #[Test]
    public function another_members_account_is_not_found_whatever_force_says(): void
    {
        $member = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $account = $this->link($other, 'google');

        $this->detach($this->editorWith('users.acp.update'), $member, $account, ['force' => true])->assertNotFound();

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
    }
}
