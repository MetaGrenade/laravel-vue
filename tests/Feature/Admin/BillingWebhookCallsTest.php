<?php

namespace Tests\Feature\Admin;

use App\Models\BillingWebhookCall;
use App\Models\User;
use App\Support\Billing\BillingWebhookProcessor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingWebhookCallsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_view_webhook_calls(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        BillingWebhookCall::factory()->create([
            'type' => 'invoice.payment_succeeded',
        ]);

        $response = $this->actingAs($user)->get(route('acp.billing.webhooks.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingWebhookCalls')
            ->has('calls.data', 1, fn (Assert $row) => $row
                ->where('type', 'invoice.payment_succeeded')
                ->etc()
            )
            ->where('availableTypes', fn ($types) => in_array('invoice.payment_succeeded', $types, true))
        );
    }

    public function test_unauthorized_users_cannot_view_webhook_calls(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('acp.billing.webhooks.index'))
            ->assertForbidden();
    }

    public function test_authorized_staff_can_view_single_webhook_call(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $call = BillingWebhookCall::factory()->create([
            'stripe_id' => 'evt_test123',
            'type' => 'invoice.payment_failed',
            'payload' => ['id' => 'evt_test123', 'type' => 'invoice.payment_failed'],
        ]);

        $response = $this->actingAs($user)->get(route('acp.billing.webhooks.show', $call));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingWebhookCallView')
            ->where('call.stripe_id', 'evt_test123')
            ->where('call.type', 'invoice.payment_failed')
            ->etc()
        );
    }

    public function test_replay_dispatches_processor(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $call = BillingWebhookCall::factory()->create([
            'stripe_id' => 'evt_replay123',
        ]);

        $processor = $this->mock(BillingWebhookProcessor::class);
        $processor->shouldReceive('replay')
            ->once()
            ->withArgs(fn ($argument) => $argument->is($call));

        $response = $this->actingAs($user)->post(route('acp.billing.webhooks.replay', $call));

        $response->assertRedirect(route('acp.billing.webhooks.show', $call));
        $response->assertSessionHas('success', 'Webhook replay dispatched.');
    }
}
