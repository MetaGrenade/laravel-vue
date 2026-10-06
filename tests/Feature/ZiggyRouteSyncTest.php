<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ZiggyRouteSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_page_loads_publish_routes_for_the_users_group_via_blade(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('"forum.index":{', false)
            ->assertDontSee('acp.dashboard', false);

        $this->actingAs($this->staff())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('acp.dashboard', false);
    }

    public function test_route_map_is_resent_when_a_staff_member_signs_in_during_an_inertia_visit(): void
    {
        // The browser loaded the guest login page, so it holds the public map.
        $response = $this->actingAs($this->staff())
            ->withHeaders($this->inertiaHeaders('public'))
            ->get(route('dashboard'));

        $response->assertOk();

        $ziggy = $response->json('props.ziggy');

        $this->assertSame('staff', $ziggy['group']);
        $this->assertArrayHasKey('acp.dashboard', $ziggy['routes']);
    }

    public function test_route_map_is_resent_without_admin_routes_after_signing_out(): void
    {
        // The browser still holds the staff map after the logout redirect.
        $response = $this->withHeaders($this->inertiaHeaders('staff'))->get(route('home'));

        $ziggy = $response->json('props.ziggy');

        $this->assertSame('public', $ziggy['group']);
        $this->assertArrayHasKey('home', $ziggy['routes']);
        $this->assertArrayNotHasKey('acp.dashboard', $ziggy['routes']);
    }

    public function test_route_map_is_not_resent_when_the_group_is_unchanged(): void
    {
        $response = $this->actingAs($this->staff())
            ->withHeaders($this->inertiaHeaders('staff'))
            ->get(route('dashboard'));

        $ziggy = $response->json('props.ziggy');

        $this->assertSame('staff', $ziggy['group']);
        $this->assertArrayNotHasKey('routes', $ziggy);
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(string $heldGroup): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Ziggy-Group' => $heldGroup,
        ];
    }

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
