<?php

namespace Tests\Feature\Admin;

use App\Models\DataErasureRequest;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrustSafetyConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.edit', 'guard_name' => 'web']);
    }

    private function createAgent(array $permissions = ['trust_safety.acp.view']): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_authorized_user_can_view_console(): void
    {
        $agent = $this->createAgent(['trust_safety.acp.view']);
        $requester = User::factory()->create();

        Carbon::setTestNow('2024-06-01 10:00:00');
        $export = DataExport::factory()->for($requester)->create([
            'status' => DataExport::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2024-06-02 15:00:00');
        $erasure = DataErasureRequest::factory()->for($requester)->create([
            'status' => DataErasureRequest::STATUS_PROCESSING,
        ]);

        Carbon::setTestNow();

        $response = $this->actingAs($agent)->get(route('acp.trust-safety.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/TrustSafety')
            ->where('exports.data.0.id', $export->id)
            ->where('erasureRequests.data.0.id', $erasure->id)
            ->where('filters.export_status', DataExport::STATUS_PENDING)
            ->where('filters.erasure_status', DataErasureRequest::STATUS_PENDING)
        );
    }

    public function test_forbidden_without_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        $this->actingAs($user)
            ->get(route('acp.trust-safety.index'))
            ->assertForbidden();
    }

    public function test_export_can_be_updated(): void
    {
        $agent = $this->createAgent(['trust_safety.acp.view', 'trust_safety.acp.edit']);
        $export = DataExport::factory()->create([
            'status' => DataExport::STATUS_PENDING,
            'file_path' => null,
            'failure_reason' => null,
        ]);

        Carbon::setTestNow('2024-07-10 12:00:00');

        $response = $this->actingAs($agent)->patch(route('acp.trust-safety.exports.update', $export), [
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => 'exports/export.zip',
            'failure_reason' => '',
            'completed_at' => '2024-07-10T12:00',
        ]);

        $response->assertRedirect();

        $export->refresh();

        $this->assertSame(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertSame('exports/export.zip', $export->file_path);
        $this->assertNull($export->failure_reason);
        $this->assertNotNull($export->completed_at);
        $this->assertTrue($export->completed_at->equalTo(Carbon::parse('2024-07-10T12:00:00')));
    }

    public function test_erasure_request_can_be_updated(): void
    {
        $agent = $this->createAgent(['trust_safety.acp.view', 'trust_safety.acp.edit']);
        $erasure = DataErasureRequest::factory()->create([
            'status' => DataErasureRequest::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2024-07-11 09:30:00');

        $response = $this->actingAs($agent)->patch(route('acp.trust-safety.erasure.update', $erasure), [
            'status' => DataErasureRequest::STATUS_COMPLETED,
            'processed_at' => '2024-07-11T09:30',
        ]);

        $response->assertRedirect();

        $erasure->refresh();

        $this->assertSame(DataErasureRequest::STATUS_COMPLETED, $erasure->status);
        $this->assertNotNull($erasure->processed_at);
        $this->assertTrue($erasure->processed_at->equalTo(Carbon::parse('2024-07-11T09:30:00')));
    }
}
