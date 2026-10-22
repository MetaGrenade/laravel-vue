<?php

namespace Tests\Feature\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\CustomerPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\RoutedStripeHttpClient;
use Tests\Support\ScriptedStripeHttpClient;
use Tests\TestCase;

/**
 * A customer can go to Stripe's hosted billing portal from the billing page. The shop makes the session (through the real Stripe SDK,
 * answered here with what Stripe would send) and sends them there; it always comes back to the billing page.
 */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL_URL = 'https://billing.stripe.com/p/session/test_abc123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);

        $this->user = User::factory()->create(['stripe_id' => 'cus_portal']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * Stripe as it answers when a portal session is asked for.
     *
     * @param  array<string, mixed>|array{0: int, 1: array<string, mixed>}|\Closure|null  $session  What it answers instead.
     */
    private function stripe(array|\Closure|null $session = null): RoutedStripeHttpClient
    {
        $client = new RoutedStripeHttpClient([
            'POST /v1/billing_portal/sessions' => $session ?? fn (array $params) => [
                'id' => 'bps_1',
                'object' => 'billing_portal.session',
                'customer' => $params['customer'],
                'url' => self::PORTAL_URL,
            ],
        ]);

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    private function open(array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)->post(route('settings.billing.portal'), [], $headers);
    }

    // --- Opening it -----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_customer_is_sent_to_a_portal_made_for_them_that_comes_back_to_the_billing_page(): void
    {
        $client = $this->stripe();

        $this->open()->assertRedirect(self::PORTAL_URL);

        $sent = $client->callsTo('POST /v1/billing_portal/sessions')[0]['params'];
        $this->assertSame('cus_portal', $sent['customer']);
        $this->assertSame(route('settings.billing.index'), $sent['return_url']);
        $this->assertArrayNotHasKey('configuration', $sent);
    }

    #[Test]
    public function a_page_driven_by_inertia_is_told_to_leave_the_site(): void
    {
        $this->stripe();

        // Inertia does not follow a redirect to another site: it is told where to go instead.
        $this->open(['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::PORTAL_URL);
    }

    #[Test]
    public function a_portal_configuration_is_asked_for_when_one_is_set(): void
    {
        config(['billing.portal.configuration' => 'bpc_members']);
        $client = $this->stripe();

        $this->open()->assertRedirect(self::PORTAL_URL);

        $this->assertSame('bpc_members', $client->callsTo('POST /v1/billing_portal/sessions')[0]['params']['configuration']);
    }

    #[Test]
    public function it_is_the_customers_own_portal_whatever_is_sent(): void
    {
        $other = User::factory()->create(['stripe_id' => 'cus_other']);
        $client = $this->stripe();

        $this->actingAs($this->user)->post(route('settings.billing.portal'), ['customer' => 'cus_other', 'return_url' => 'https://evil.example', 'user_id' => $other->id])
            ->assertRedirect(self::PORTAL_URL);

        $sent = $client->callsTo('POST /v1/billing_portal/sessions')[0]['params'];
        $this->assertSame('cus_portal', $sent['customer']);
        $this->assertSame(route('settings.billing.index'), $sent['return_url']);
    }

    // --- When it cannot be opened ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_customer_with_no_billing_account_is_told_so_and_stripe_is_not_asked(): void
    {
        $client = $this->stripe();
        $this->user->forceFill(['stripe_id' => null])->save();

        $this->open()
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('info', "You don't have a billing account with us yet. It is created when you subscribe.");

        $this->assertSame([], $client->requests);
    }

    #[Test]
    public function when_stripe_refuses_the_customer_is_told_plainly_and_the_reason_is_logged(): void
    {
        Log::spy();
        $this->stripe(ScriptedStripeHttpClient::error(400, 'You can’t create a portal session in test mode until you save your customer portal settings in test mode.'));

        $this->open()
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('error', "We couldn't open the billing portal just now. Please try again in a moment.");

        // What Stripe said is for whoever runs the site, not the customer.
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'billing portal')
            && str_contains($context['error'], 'customer portal settings')
            && $context['user_id'] === $this->user->id)->once();
    }

    #[Test]
    public function when_stripe_cannot_be_reached_it_is_the_same(): void
    {
        $this->stripe(fn () => throw new ApiConnectionException('Could not connect'));

        $this->open()
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('error', "We couldn't open the billing portal just now. Please try again in a moment.");
    }

    #[Test]
    public function an_address_that_is_not_secure_is_never_followed(): void
    {
        $this->stripe(fn (array $params) => ['id' => 'bps_1', 'object' => 'billing_portal.session', 'customer' => $params['customer'], 'url' => 'http://billing.stripe.com/p/session/test_abc123']);

        $this->open()
            ->assertRedirect(route('settings.billing.index'))
            ->assertSessionHas('error');
    }

    // --- Switching it off, who can, and how often -----------------------------------------------------------------------------------------

    #[Test]
    public function a_site_can_switch_the_portal_off(): void
    {
        config(['billing.portal.enabled' => false]);
        $client = $this->stripe();

        $this->open()->assertNotFound();
        $this->assertSame([], $client->requests);

        $this->actingAs($this->user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page->where('portal', false));
    }

    #[Test]
    public function only_somebody_signed_in_can_open_it(): void
    {
        $client = $this->stripe();

        $this->post(route('settings.billing.portal'))->assertRedirect(route('login'));
        $this->assertSame([], $client->requests);
    }

    #[Test]
    public function each_try_makes_a_session_at_stripe_so_they_are_limited(): void
    {
        $client = $this->stripe();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->open()->assertRedirect(self::PORTAL_URL);
        }

        $this->open()->assertTooManyRequests();
        $this->assertCount(10, $client->callsTo('POST /v1/billing_portal/sessions'));
    }

    // --- The billing page -----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_billing_page_offers_the_portal_to_a_customer_stripe_knows(): void
    {
        SubscriptionPlan::factory()->create();

        $this->actingAs($this->user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page->where('portal', true));
    }

    #[Test]
    public function nobody_who_is_not_signed_in_is_offered_it(): void
    {
        $this->assertFalse(app(CustomerPortal::class)->availableTo(null));
        $this->assertTrue(app(CustomerPortal::class)->availableTo($this->user));
    }

    #[Test]
    public function the_reload_after_subscribing_carries_the_portal_for_a_customer_who_has_just_got_an_account(): void
    {
        $user = User::factory()->create(['stripe_id' => null]);

        // What the page asks for once a subscription has started: a partial reload of just these props, which keeps all the others (so
        // a stale "no portal" would hide the button from somebody who now has a billing account).
        $version = null;
        $this->actingAs($user)->get(route('settings.billing.index'))->assertInertia(function (Assert $page) use (&$version) {
            $version = $page->toArray()['version'];

            $page->where('portal', false);
        });

        $user->forceFill(['stripe_id' => 'cus_new'])->save();

        $this->actingAs($user->fresh())->get(route('settings.billing.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'settings/Billing',
            'X-Inertia-Partial-Data' => 'subscription,invoices,trial_eligible,portal',
        ])
            ->assertOk()
            ->assertJsonPath('props.portal', true)
            ->assertJsonMissingPath('props.plans');
    }

    #[Test]
    public function the_billing_page_does_not_offer_it_when_it_would_not_work(): void
    {
        // No billing account yet.
        $this->actingAs(User::factory()->create(['stripe_id' => null]))->get(route('settings.billing.index'))
            ->assertInertia(fn (Assert $page) => $page->where('portal', false));

        // Stripe is not set up.
        config(['cashier.secret' => '']);
        $this->actingAs($this->user)->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page->where('portal', false));
    }
}
