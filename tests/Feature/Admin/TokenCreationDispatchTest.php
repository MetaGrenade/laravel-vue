<?php

namespace Tests\Feature\Admin;

use App\Jobs\RecordTokenCreatedActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TokenCreationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_token_dispatches_logging_job(): void
    {
        Queue::fake();

        $permission = Permission::firstOrCreate(['name' => 'tokens.acp.create']);
        $role = Role::firstOrCreate(['name' => 'admin']);
        $role->givePermissionTo($permission);

        $admin = User::factory()->create();
        $admin->assignRole($role);

        $tokenOwner = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('acp.tokens.store'), [
            'name' => 'API Token',
            'user_id' => $tokenOwner->id,
            'abilities' => ['*'],
            'expires_at' => Carbon::now()->addMonth()->toIso8601String(),
            'hourly_quota' => 100,
            'daily_quota' => 200,
        ]);

        $response->assertRedirect(route('acp.tokens.index'));
        $response->assertSessionHas('success', 'Token created.');

        Queue::assertPushed(RecordTokenCreatedActivity::class, function ($job) use ($admin) {
            return $job->actorId === $admin->id && $job->tokenId > 0;
        });
    }
}
