<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_activity_timestamp_is_exposed(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $lastActiveAt = now()->subMinutes(2)->startOfSecond();

        $recentUser = User::factory()->create([
            'last_activity_at' => $lastActiveAt,
        ]);

        $response = $this->get(route('acp.users.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('users.data', function ($users) use ($recentUser, $lastActiveAt) {
                return collect($users)->contains(function ($user) use ($recentUser, $lastActiveAt) {
                    return $user['id'] === $recentUser->id
                        && $user['last_activity_at'] === $lastActiveAt->toIso8601String();
                });
            })
        );
    }
}
