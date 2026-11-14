<?php

namespace Tests\Feature\Commerce;

use App\Payments\Stripe\StripeHttp;
use Laravel\Cashier\Cashier;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Tests\TestCase;

/**
 * A call to Stripe that never comes back is given up on after `STRIPE_TIMEOUT` seconds. The library's own limit is 80, which is longer
 * than a queue worker lets a job run (60), so before this one call that hung cost the whole job (docs/performance.md).
 *
 * The limits are kept on the library's one shared client, which outlives a test, so each test puts back what it found. So does the
 * number of times the library tries a call again: Cashier's webhook controller turns it up to 3 for the rest of the process the first
 * time it handles an event, and in a full run that is every test after a webhook test. A call that timed out would then be made four
 * times and take four times as long, so this test sets it to 0 and puts the number back.
 */
class StripeCallTimeoutTest extends TestCase
{
    private int $timeout;

    private int $connectTimeout;

    private int $retries;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $client = CurlClient::instance();
        $this->timeout = $client->getTimeout();
        $this->connectTimeout = $client->getConnectTimeout();

        $this->retries = Stripe::getMaxNetworkRetries();
        Stripe::setMaxNetworkRetries(0);

        // Other tests put a scripted client in its place and some do not put the real one back.
        ApiRequestor::setHttpClient($client);
    }

    protected function tearDown(): void
    {
        CurlClient::instance()->setTimeout($this->timeout)->setConnectTimeout($this->connectTimeout);
        Stripe::setMaxNetworkRetries($this->retries);

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_call_waits_fifteen_seconds_for_an_answer_and_five_to_connect_unless_told_otherwise(): void
    {
        // The application's own boot set these, from config/services.php; nothing in this test did.
        $this->assertSame(15, CurlClient::instance()->getTimeout());
        $this->assertSame(5, CurlClient::instance()->getConnectTimeout());
    }

    #[Test]
    public function the_two_limits_come_from_the_environment_each_from_its_own_key(): void
    {
        config(['services.stripe.timeout' => 30, 'services.stripe.connect_timeout' => 10]);
        StripeHttp::configure();

        $this->assertSame(30, CurlClient::instance()->getTimeout());
        $this->assertSame(10, CurlClient::instance()->getConnectTimeout());

        // 0 is the library's (and curl's) "wait for ever", for somebody who wants the old behaviour back.
        config(['services.stripe.timeout' => 0, 'services.stripe.connect_timeout' => 0]);
        StripeHttp::configure();

        $this->assertSame(0, CurlClient::instance()->getTimeout());
        $this->assertSame(0, CurlClient::instance()->getConnectTimeout());
    }

    #[Test]
    public function a_services_file_without_the_keys_gets_the_same_limits_and_not_no_limit(): void
    {
        // A config/services.php written before these keys existed has no `timeout`: reading that as 0 would mean waiting for ever.
        config(['services.stripe' => ['secret' => 'sk_test_old_file']]);
        StripeHttp::configure();

        $this->assertSame(StripeHttp::TIMEOUT, CurlClient::instance()->getTimeout());
        $this->assertSame(StripeHttp::CONNECT_TIMEOUT, CurlClient::instance()->getConnectTimeout());
    }

    #[Test]
    public function the_defaults_in_the_class_and_in_the_config_file_are_the_same_numbers(): void
    {
        $this->assertSame(StripeHttp::TIMEOUT, config('services.stripe.timeout'));
        $this->assertSame(StripeHttp::CONNECT_TIMEOUT, config('services.stripe.connect_timeout'));
    }

    #[Test]
    public function a_call_to_a_server_that_never_answers_fails_when_the_limit_is_reached_and_not_before(): void
    {
        // A server that accepts the connection (the operating system does, from its queue) and then says nothing, the way a stuck one does.
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $error);
        $this->assertNotFalse($this->server, "could not listen on a local port: {$error}");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($this->server, false), ':'), 1);

        config(['cashier.secret' => 'sk_test_silent', 'services.stripe.timeout' => 2]);
        StripeHttp::configure();

        $started = microtime(true);

        try {
            Cashier::stripe(['api_base' => "http://127.0.0.1:{$port}"])->checkout->sessions->retrieve('cs_test_silent');
            $this->fail('A server that never answers was answered.');
        } catch (ApiConnectionException $exception) {
            $waited = microtime(true) - $started;

            // curl's own words for a call that ran out of time, error 28.
            $this->assertStringContainsString('28', $exception->getMessage());
        }

        $this->assertGreaterThanOrEqual(1.8, $waited, 'it waited for the whole limit, so a call that is only slow is not cut off');
        $this->assertLessThan(10, $waited, 'and not for the library\'s 80 seconds');
    }
}
