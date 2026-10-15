<?php

namespace Tests\Feature\Tebex;

use App\Payments\Exceptions\PaymentException;
use App\Payments\Tebex\TebexException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The Headless API client, against answers shaped like Tebex's own.
 */
class TebexClientTest extends TestCase
{
    use InteractsWithTebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
    }

    #[Test]
    public function the_store_is_read_from_the_public_token_alone(): void
    {
        $this->fakeTebex();

        $store = $this->tebexClient()->webstore();

        $this->assertSame(123456, $store->id);
        $this->assertSame('Example Store', $store->name);
        $this->assertSame('USD', $store->currency);
        $this->assertSame('https://example-store.tebex.io', $store->url);
        $this->assertFalse($store->disabled);
        $this->assertTrue($store->supportsUsernames);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === "https://headless.tebex.io/api/accounts/{$this->tebexToken}"
                && $request->hasHeader('Accept', 'application/json')
                // The private key is only for calls that return a customer's own data: nothing here uses it.
                && ! $request->hasHeader('Authorization')
                && ! str_contains((string) json_encode($request->headers()), 'private-key');
        });
    }

    #[Test]
    public function the_catalogue_is_asked_for_with_its_packages(): void
    {
        $this->fakeTebex();

        $categories = $this->tebexClient()->categories();

        $this->assertCount(2, $categories);
        $this->assertSame('Ranks', $categories[0]['name']);
        $this->assertCount(2, $categories[0]['packages']);

        Http::assertSent(fn (Request $request) => $request->url() === "https://headless.tebex.io/api/accounts/{$this->tebexToken}/categories?includePackages=1");
    }

    #[Test]
    public function nothing_is_sent_when_no_token_is_set(): void
    {
        Http::fake();
        config(['tebex.public_token' => null]);

        $this->assertFalse($this->tebexClient()->isConfigured());

        try {
            $this->tebexClient()->webstore();
            $this->fail('A call went out with no token.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('TEBEX_PUBLIC_TOKEN', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_failure_is_a_payment_exception_so_checkout_can_handle_it_like_any_provider_failing(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $this->expectException(PaymentException::class);

        $this->tebexClient()->webstore();
    }

    #[Test]
    public function a_server_error_is_asked_again_and_the_later_answer_is_used(): void
    {
        Http::fake([
            'headless.tebex.io/*' => Http::sequence()
                ->push('Bad gateway', 502)
                ->push(['title' => 'Service unavailable'], 503)
                ->push($this->tebexFixture('webstore')),
        ]);

        $this->assertSame('Example Store', $this->tebexClient()->webstore()->name);

        Http::assertSentCount(3);
    }

    #[Test]
    public function being_told_to_slow_down_is_asked_again(): void
    {
        Http::fake([
            'headless.tebex.io/*' => Http::sequence()
                ->push(['title' => 'Too many requests', 'status' => 429], 429)
                ->push($this->tebexFixture('webstore')),
        ]);

        $this->assertSame(123456, $this->tebexClient()->webstore()->id);

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_server_that_stays_down_is_given_up_on_after_the_last_wait_with_a_message_for_staff(): void
    {
        Http::fake(['headless.tebex.io/*' => Http::response('Down', 503)]);

        try {
            $this->tebexClient()->webstore();
            $this->fail('A failing server was reported as working.');
        } catch (TebexException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertStringContainsString('Tebex answered 503', $exception->getMessage());
            $this->assertStringContainsString('problem of its own', $exception->getMessage());
        }

        // Once, and once more after each of the three waits.
        Http::assertSentCount(4);
    }

    #[Test]
    public function an_answer_that_will_not_change_is_not_asked_for_again(): void
    {
        Http::fake(['headless.tebex.io/*' => Http::response([
            'type' => 'about:blank',
            'title' => 'Unprocessable',
            'status' => 422,
            'detail' => 'The webstore id is invalid.',
            'instance' => '/accounts/x',
        ], 422)]);

        try {
            $this->tebexClient()->webstore();
            $this->fail('A refusal was reported as working.');
        } catch (TebexException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('Unprocessable: The webstore id is invalid.', $exception->getMessage());
            $this->assertFalse($exception->isAuthentication());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function refused_credentials_and_an_unknown_store_say_what_to_check(): void
    {
        Http::fake(['headless.tebex.io/*' => Http::sequence()->push([], 401)->push([], 404)]);

        try {
            $this->tebexClient()->webstore();
            $this->fail('Refused credentials were reported as working.');
        } catch (TebexException $exception) {
            $this->assertTrue($exception->isAuthentication());
            $this->assertStringContainsString('check the public token and private key', $exception->getMessage());
        }

        try {
            $this->tebexClient()->webstore();
            $this->fail('An unknown store was reported as working.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('does not know this store', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_network_failure_is_retried_then_reported_without_the_token(): void
    {
        $attempts = 0;

        Http::fake(['headless.tebex.io/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException("cURL error 28: Operation timed out after 10001 milliseconds for https://headless.tebex.io/api/accounts/{$this->tebexToken}");
        }]);

        try {
            $this->tebexClient()->webstore();
            $this->fail('A network failure was reported as working.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('could not be reached', $exception->getMessage());
            $this->assertStringContainsString('[token]', $exception->getMessage());
            $this->assertStringNotContainsString($this->tebexToken, $exception->getMessage());
        }

        $this->assertSame(4, $attempts);
    }

    #[Test]
    public function the_token_is_taken_out_of_what_tebex_says_back(): void
    {
        Http::fake(['headless.tebex.io/*' => Http::response(['title' => 'Bad request', 'detail' => "Nothing for {$this->tebexToken}"], 400)]);

        try {
            $this->tebexClient()->webstore();
            $this->fail('A refusal was reported as working.');
        } catch (TebexException $exception) {
            $this->assertStringNotContainsString($this->tebexToken, $exception->getMessage());
            $this->assertStringContainsString('Nothing for [token]', $exception->getMessage());
        }
    }

    #[Test]
    public function a_category_that_is_not_an_object_is_refused_rather_than_dropped_from_the_list(): void
    {
        // Dropped, it would look like a category Tebex no longer lists, and the shop would retire it.
        foreach (['oops', null, 7] as $broken) {
            $body = $this->tebexFixture('categories-with-packages');
            $body['data'][1] = $broken;
            $this->fakeTebex($body);

            try {
                $this->tebexClient()->categories();
                $this->fail('A category that is not an object was accepted.');
            } catch (TebexException $exception) {
                $this->assertStringContainsString('category the shop could not understand', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_json_answer_with_no_data_in_it_is_refused_rather_than_read_as_empty(): void
    {
        Http::fake(['headless.tebex.io/*' => Http::response(['message' => 'Something else entirely'])]);

        foreach ([
            fn () => $this->tebexClient()->webstore(),
            fn () => $this->tebexClient()->categories(),
        ] as $call) {
            try {
                $call();
                $this->fail('An answer with nothing in it was accepted.');
            } catch (TebexException $exception) {
                $this->assertStringContainsString('could not understand', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function an_answer_that_is_not_what_tebex_documents_is_refused(): void
    {
        Http::fake([
            'headless.tebex.io/api/accounts/*/categories*' => Http::response(['data' => ['id' => 1, 'name' => 'Not a list']]),
            'headless.tebex.io/api/accounts/*' => Http::response('<html>maintenance</html>'),
        ]);

        foreach ([
            fn () => $this->tebexClient()->webstore(),
            fn () => $this->tebexClient()->categories(),
        ] as $call) {
            try {
                $call();
                $this->fail('A strange answer was accepted.');
            } catch (TebexException $exception) {
                $this->assertStringContainsString('could not understand', $exception->getMessage());
            }
        }
    }
}
