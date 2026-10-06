<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_monthly_chart_data(): void
    {
        $admin = User::factory()->create(['created_at' => now()->subMonth()]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));

        User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->get(route('acp.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('acp/Dashboard')->etc());
    }

    public function test_guests_and_members_cannot_open_the_admin_dashboard(): void
    {
        $this->get(route('acp.dashboard'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('acp.dashboard'))
            ->assertForbidden();
    }
}
