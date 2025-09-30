<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_availability_respects_maintenance_toggle(): void
    {
        $this->get(route('home'))->assertOk();

        SystemSetting::set('maintenance_mode', true);

        $this->get(route('home'))->assertStatus(503);

        $admin = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $admin->assignRole($role);

        $this->actingAs($admin);

        $this->get(route('home'))->assertOk();

        SystemSetting::set('maintenance_mode', false);

        $this->get(route('home'))->assertOk();
    }
}
