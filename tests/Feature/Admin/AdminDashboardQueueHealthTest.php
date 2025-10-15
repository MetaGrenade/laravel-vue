<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_includes_queue_health_data(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);

        $now = Carbon::now();

        $queueConnection = config('queue.connections.database.connection') ?? config('database.default');
        $jobsTable = config('queue.connections.database.table', 'jobs');

        DB::connection($queueConnection)->table($jobsTable)->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now->copy()->subMinutes(5)->timestamp,
            'created_at' => $now->copy()->subMinutes(6)->timestamp,
        ]);

        $failedConnection = config('queue.failed.database') ?? config('database.default');
        $failedTable = config('queue.failed.table', 'failed_jobs');

        DB::connection($failedConnection)->table($failedTable)->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Example failure message for testing.',
            'failed_at' => $now->copy()->subMinute(),
        ]);

        $response = $this->actingAs($admin)->get(route('acp.dashboard'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Dashboard')
            ->where('queueHealth.pending', 1)
            ->where('queueHealth.failed', 1)
            ->where('queueHealth.queue', 'default')
            ->where('queueHealth.recent_failures.0.queue', 'default')
            ->where('queueHealth.workers', fn ($workers) => is_array($workers)) // Accept empty arrays (or populated arrays) for queueHealth.workers
        );
    }
}
