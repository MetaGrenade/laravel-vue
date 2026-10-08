<?php

namespace Tests\Feature\Commerce;

use App\Payments\Exceptions\RefundRejected;
use App\Payments\Stripe\CashierStripeGateway;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ScriptedStripeHttpClient as Stripe;
use Tests\TestCase;

/**
 * The real gateway through the real Stripe SDK, with the network replaced by scripted answers.
 */
class CashierStripeGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function stripeAnswers(array $responses): Stripe
    {
        $client = new Stripe($responses);
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    #[Test]
    public function every_page_of_refunds_is_read(): void
    {
        $first = array_map(fn (int $n) => Stripe::refund("re_{$n}", $n), range(1, 100));
        $second = array_map(fn (int $n) => Stripe::refund("re_{$n}", $n), range(101, 120));
        $client = $this->stripeAnswers([Stripe::page($first, hasMore: true), Stripe::page($second, hasMore: false)]);

        $refunds = (new CashierStripeGateway)->listRefunds('pi_1');

        $this->assertCount(120, $refunds);
        $this->assertSame('re_1', $refunds[0]['id']);
        $this->assertSame('re_120', $refunds[119]['id'], 'refunds from the second page are included');
        $this->assertSame(range(1, 120), array_column($refunds, 'amount'));

        // The second request continues after the last refund of the first page, for the same payment.
        $this->assertCount(2, $client->requests);
        $this->assertSame('pi_1', $client->requests[0]['params']['payment_intent']);
        $this->assertSame(100, (int) $client->requests[0]['params']['limit']);
        $this->assertArrayNotHasKey('starting_after', $client->requests[0]['params']);
        $this->assertSame('pi_1', $client->requests[1]['params']['payment_intent']);
        $this->assertSame('re_100', $client->requests[1]['params']['starting_after']);
    }

    #[Test]
    public function a_payment_with_a_single_page_is_read_in_one_request(): void
    {
        $client = $this->stripeAnswers([Stripe::page([Stripe::refund('re_1'), Stripe::refund('re_2')], hasMore: false)]);

        $refunds = (new CashierStripeGateway)->listRefunds('pi_1');

        $this->assertSame(['re_1', 're_2'], array_column($refunds, 'id'));
        $this->assertCount(1, $client->requests);
    }

    #[Test]
    public function a_payment_with_no_refunds_gives_an_empty_list(): void
    {
        $this->stripeAnswers([Stripe::page([], hasMore: false)]);

        $this->assertSame([], (new CashierStripeGateway)->listRefunds('pi_1'));
    }

    #[Test]
    public function a_failure_part_way_through_the_pages_is_not_mistaken_for_a_short_list(): void
    {
        $first = array_map(fn (int $n) => Stripe::refund("re_{$n}"), range(1, 100));
        $this->stripeAnswers([Stripe::page($first, hasMore: true), Stripe::error(500, 'Something went wrong on our end.', 'api_error')]);

        // Reading only the first page and carrying on would record a partial picture as the whole one.
        $this->expectException(ApiErrorException::class);

        (new CashierStripeGateway)->listRefunds('pi_1');
    }

    #[Test]
    public function a_refund_is_created_with_its_idempotency_key(): void
    {
        $client = $this->stripeAnswers([[200, Stripe::refund('re_9', 2500)]]);

        $refund = (new CashierStripeGateway)->createRefund(['payment_intent' => 'pi_1', 'amount' => 2500], 'refund-abc');

        $this->assertSame('re_9', $refund['id']);
        $this->assertSame('post', $client->requests[0]['method']);
        $this->assertContains('Idempotency-Key: refund-abc', $client->requests[0]['headers']);
        $this->assertSame(2500, (int) $client->requests[0]['params']['amount']);
    }

    #[Test]
    public function stripe_saying_no_to_a_refund_is_a_definite_refusal(): void
    {
        foreach ([400, 404] as $status) {
            $this->stripeAnswers([Stripe::error($status, 'Charge ch_1 has already been refunded.')]);

            try {
                (new CashierStripeGateway)->createRefund(['payment_intent' => 'pi_1', 'amount' => 100], "refund-{$status}");
                $this->fail("A {$status} answer was not treated as a refusal.");
            } catch (RefundRejected $exception) {
                $this->assertSame('Charge ch_1 has already been refunded.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function any_other_failure_leaves_the_outcome_unknown(): void
    {
        // None of these say that no money moved: a request still in flight, Stripe failing, a rate
        // limit and a dropped connection. They must stay ordinary errors, never RefundRejected.
        $cases = [
            'conflict' => Stripe::error(409, 'A request with this key is still being processed.'),
            'rate limited' => Stripe::error(429, 'Too many requests.', 'rate_limit_error'),
            'server error' => Stripe::error(500, 'Something went wrong on our end.', 'api_error'),
            'dropped connection' => new ApiConnectionException('Could not connect to Stripe.'),
        ];

        foreach ($cases as $name => $answer) {
            $this->stripeAnswers([$answer]);

            try {
                (new CashierStripeGateway)->createRefund(['payment_intent' => 'pi_1', 'amount' => 100], 'refund-x');
                $this->fail("{$name}: no error was raised.");
            } catch (RefundRejected) {
                $this->fail("{$name} was treated as a definite refusal, but the refund may exist.");
            } catch (ApiErrorException|ApiConnectionException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
