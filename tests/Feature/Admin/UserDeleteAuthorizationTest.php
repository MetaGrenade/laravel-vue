<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient as Stripe;
use Tests\TestCase;

/**
 * Deleting one user from the ACP needs `users.acp.delete`, as the bulk delete does: reaching the ACP as staff is not enough.
 * (The admin role passes every check, so these use the editor role.)
 */
class UserDeleteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);

        Role::create(['name' => 'editor', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.delete', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.acp.ban', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    private function editorWith(string ...$permissions): User
    {
        $editor = User::factory()->create();
        $editor->assignRole('editor');
        $editor->givePermissionTo($permissions);

        return $editor;
    }

    #[Test]
    public function staff_without_the_delete_permission_cannot_delete_a_user(): void
    {
        $client = new Stripe([]);
        ApiRequestor::setHttpClient($client);
        $target = User::factory()->create(['stripe_id' => 'cus_1']);

        // Another user permission is not enough.
        $this->actingAs($this->editorWith('users.acp.ban'))
            ->delete(route('acp.users.destroy', $target))
            ->assertForbidden();

        $this->assertNotNull($target->fresh());
        $this->assertSame([], $client->requests, 'a refused request cancels nothing at Stripe');
    }

    #[Test]
    public function staff_with_the_delete_permission_can_delete_a_user(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->editorWith('users.acp.delete'))
            ->from(route('acp.users.index'))
            ->delete(route('acp.users.destroy', $target))
            ->assertRedirect(route('acp.users.index'))
            ->assertSessionHas('success', 'User deleted.');

        $this->assertNull($target->fresh());
    }
}
