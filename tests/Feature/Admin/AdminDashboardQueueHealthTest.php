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

        $now = Carbon::parse('2024-09-01 12:00:00', 'UTC');
        $failedAt = $now->copy()->subMinute();
        $oldestAvailableAt = $now->copy()->subMinutes(4);

        $originalQueueConfig = [
            'default' => config('queue.default'),
            'connection' => config('queue.connections.database'),
            'failed' => config('queue.failed'),
            'workers' => config('queue.workers'),
        ];

        Carbon::setTestNow($now);

        try {
            $databaseConnectionConfig = array_merge(
                config('queue.connections.database', []),
                [
                    'connection' => config('database.default'),
                    'queue' => 'critical',
                    'table' => 'jobs',
                    'queues' => ['critical'],
                ],
            );

            $failedConnectionConfig = array_merge(
                config('queue.failed', []),
                [
                    'database' => config('database.default'),
                    'table' => 'failed_jobs',
                ],
            );

            config()->set('queue.default', 'database');
            config()->set('queue.connections.database', $databaseConnectionConfig);
            config()->set('queue.failed', $failedConnectionConfig);
            config()->set('queue.workers', [
                [
                    'name' => 'primary',
                    'connection' => 'database',
                    'queues' => ['critical', 'default'],
                    'tries' => 5,
                    'backoff' => 10,
                    'sleep' => 3,
                    'timeout' => 120,
                    'max_jobs' => 200,
                    'max_time' => 3600,
                ],
                [
                    'name' => 'notifications',
                    'connection' => 'database',
                    'queues' => ['mail'],
                    'tries' => 3,
                    'backoff' => 5,
                    'sleep' => 5,
                    'timeout' => 90,
                    'max_jobs' => 500,
                    'max_time' => 1800,
                ],
            ]);

            $jobsConnection = $databaseConnectionConfig['connection'];
            $jobsTable = $databaseConnectionConfig['table'];

            DB::connection($jobsConnection)->table($jobsTable)->insert([
                'queue' => 'critical',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $oldestAvailableAt->timestamp,
                'created_at' => $now->copy()->subMinutes(5)->timestamp,
            ]);

            $failedConnection = $failedConnectionConfig['database'];
            $failedTable = $failedConnectionConfig['table'];

            DB::connection($failedConnection)->table($failedTable)->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'critical',
                'payload' => '{}',
                'exception' => 'Example failure message for testing.',
                'failed_at' => $failedAt,
            ]);

            $response = $this->actingAs($admin)->get(route('acp.dashboard'));

            $response->assertOk();

            $response->assertInertia(fn (Assert $page) => $page
                ->component('acp/Dashboard')
                ->has('queueHealth', fn (Assert $queue) => $queue
                    ->where('connection', 'database')
                    ->where('queue', 'critical')
                    ->where('pending', 1)
                    ->where('failed', 1)
                    ->where('oldest_pending_available_at', $oldestAvailableAt->toIso8601String())
                    ->where('oldest_pending_age_seconds', 240)
                    ->where('last_failed_at', $failedAt->toIso8601String())
                    ->has('recent_failures', 1, fn (Assert $failure) => $failure
                        ->where('queue', 'critical')
                        ->where('connection', 'database')
                        ->where('failed_at', $failedAt->toIso8601String())
                        ->where('exception_excerpt', 'Example failure message for testing.')
                        ->where('id', fn ($id) => ! empty($id))
                        ->etc()
                    )
                    ->has('workers', 2)
                    ->where('workers.0.name', 'primary')
                    ->where('workers.0.connection', 'database')
                    ->where('workers.0.queues', fn ($queues) => $queues === ['critical', 'default'])
                    ->where('workers.0.tries', 5)
                    ->where('workers.0.backoff', 10)
                    ->where('workers.0.sleep', 3)
                    ->where('workers.0.timeout', 120)
                    ->where('workers.0.max_jobs', 200)
                    ->where('workers.0.max_time', 3600)
                    ->where('workers.1.name', 'notifications')
                    ->where('workers.1.connection', 'database')
                    ->where('workers.1.queues', fn ($queues) => $queues === ['mail'])
                    ->where('workers.1.tries', 3)
                    ->where('workers.1.backoff', 5)
                    ->where('workers.1.sleep', 5)
                    ->where('workers.1.timeout', 90)
                    ->where('workers.1.max_jobs', 500)
                    ->where('workers.1.max_time', 1800)
                    ->etc()
                )
            );
        } finally {
            Carbon::setTestNow();
            config()->set('queue.default', $originalQueueConfig['default']);
            config()->set('queue.connections.database', $originalQueueConfig['connection']);
            config()->set('queue.failed', $originalQueueConfig['failed']);
            config()->set('queue.workers', $originalQueueConfig['workers']);
        }
    }
}
