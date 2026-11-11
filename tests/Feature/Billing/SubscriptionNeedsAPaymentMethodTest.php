<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Starting a subscription with no payment method is refused before anything is asked of Stripe. The pages already require one, so this is
 * what a caller other than those pages (a command, a job, the next feature) gets. The refusal used to be `IncompletePayment::invalidPaymentMethod()`,
 * a method Cashier does not have, so it ended in "Call to undefined method" and a fatal error instead of a refusal.
 */
class SubscriptionNeedsAPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_empty_payment_method_is_refused_with_a_message_and_starts_nothing(): void
    {
        Http::preventStrayRequests();

        $user = User::factory()->create(['stripe_id' => 'cus_me']);
        $plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_pro']);

        try {
            app(SubscriptionManager::class)->create($user, $plan, '');
            $this->fail('A subscription was started with no payment method.');
        } catch (InvalidArgumentException $refused) {
            $this->assertStringContainsString('payment method', $refused->getMessage());
        }

        $this->assertSame(0, Subscription::query()->count());
    }
}
