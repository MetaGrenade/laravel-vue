<?php

namespace Tests\Feature\Admin;

use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_view_billing_invoices(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $plan = SubscriptionPlan::factory()->create(['name' => 'Starter']);
        BillingInvoice::factory()->for($user)->for($plan, 'plan')->create([
            'stripe_id' => 'in_test123',
            'total' => 2500,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)->get(route('acp.billing.invoices.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingInvoices')
            ->has('invoices.data', 1, fn (Assert $row) => $row
                ->where('stripe_id', 'in_test123')
                ->etc()
            )
        );
    }

    public function test_invoices_can_be_filtered_by_status_search_and_date_range(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $agent = User::factory()->create();
        $agent->givePermissionTo('billing.acp.view');

        $plan = SubscriptionPlan::factory()->create(['name' => 'Pro']);
        $included = BillingInvoice::factory()
            ->for($agent)
            ->for($plan, 'plan')
            ->create([
                'status' => 'paid',
                'stripe_id' => 'in_matching',
                'created_at' => Carbon::now()->subDays(3),
            ]);

        BillingInvoice::factory()->create([
            'status' => 'open',
            'stripe_id' => 'in_nonmatch',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        BillingInvoice::factory()->create([
            'status' => 'paid',
            'stripe_id' => 'in_old',
            'created_at' => Carbon::now()->subDays(15),
        ]);

        $response = $this->actingAs($agent)->get(route('acp.billing.invoices.index', [
            'status' => 'paid',
            'search' => 'matching',
            'date_from' => Carbon::now()->subDays(5)->toDateString(),
            'date_to' => Carbon::now()->subDays(1)->toDateString(),
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingInvoices')
            ->has('invoices.data', 1, fn (Assert $row) => $row
                ->where('id', $included->id)
                ->where('stripe_id', 'in_matching')
                ->where('status', 'paid')
                ->etc()
            )
            ->where('filters.status', 'paid')
            ->where('filters.search', 'matching')
        );
    }

    public function test_invoices_can_be_exported_as_csv_and_excel(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $agent = User::factory()->create();
        $agent->givePermissionTo('billing.acp.view');

        $invoice = BillingInvoice::factory()->for($agent)->create([
            'status' => 'paid',
            'stripe_id' => 'in_export',
        ]);

        $otherInvoice = BillingInvoice::factory()->create([
            'status' => 'open',
            'stripe_id' => 'in_ignore',
        ]);

        $csvResponse = $this->actingAs($agent)->get(route('acp.billing.invoices.export', [
            'status' => 'paid',
            'format' => 'csv',
        ]));

        $csvResponse->assertOk();
        $csvResponse->assertHeader('content-type', 'text/csv');
        $csvContent = $csvResponse->getContent();
        $this->assertStringContainsString($invoice->stripe_id, $csvContent);
        $this->assertStringNotContainsString($otherInvoice->stripe_id, $csvContent);

        $excelResponse = $this->actingAs($agent)->get(route('acp.billing.invoices.export', [
            'format' => 'xlsx',
        ]));

        $excelResponse->assertOk();
        $excelResponse->assertHeader('content-type', 'application/vnd.ms-excel');
        $excelContent = $excelResponse->getContent();
        $this->assertStringContainsString($invoice->stripe_id, $excelContent);
        $this->assertStringContainsString($otherInvoice->stripe_id, $excelContent);
    }
}
