<?php

namespace Tests\Feature\Tebex;

use App\Models\TebexPackage;
use App\Payments\Tebex\TebexException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The basket calls of the Headless API client.
 */
class TebexBasketClientTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
    }

    private function vip(): TebexPackage
    {
        return TebexPackage::factory()->priced('10.00')->create(['tebex_id' => 50001, 'ident' => 'vip-rank', 'name' => 'VIP']);
    }

    #[Test]
    public function a_basket_is_made_with_where_the_buyer_comes_back_to_and_what_to_give_back(): void
    {
        $tebex = $this->fakeTebexBaskets();

        $basket = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', ['order' => 'abc', 'nonce' => 'n1'], '203.0.113.9');

        $this->assertStringStartsWith('bsk', $basket->ident);
        $this->assertFalse($basket->complete);
        $this->assertFalse($basket->isPaid());
        $this->assertSame("https://pay.tebex.io/{$basket->ident}", $basket->checkoutUrl);
        $this->assertNull($basket->paymentUrl);
        $this->assertSame('USD', $basket->currency);
        $this->assertSame([], $basket->packages);
        $this->assertSame(['order' => 'abc', 'nonce' => 'n1'], $basket->custom);

        $call = $tebex->calls('create')[0];
        $this->assertSame("/api/accounts/{$this->tebexToken}/baskets", $call['path']);
        $this->assertSame([
            'complete_url' => 'https://shop.test/done',
            'cancel_url' => 'https://shop.test/cart',
            'custom' => ['order' => 'abc', 'nonce' => 'n1'],
            'complete_auto_redirect' => true,
            'ip_address' => '203.0.113.9',
        ], $call['body']);
        // Nothing here is a customer's own data, so nothing uses the private key.
        $this->assertArrayNotHasKey('Authorization', $call['headers']);
    }

    #[Test]
    public function without_an_address_for_the_buyer_none_is_sent(): void
    {
        $tebex = $this->fakeTebexBaskets();

        $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', ['order' => 'abc'], null);

        $this->assertArrayNotHasKey('ip_address', $tebex->calls('create')[0]['body']);
    }

    #[Test]
    public function a_package_is_added_by_its_ident_and_the_basket_says_what_it_costs(): void
    {
        $this->vip();
        $tebex = $this->fakeTebexBaskets();
        $tebex->taxRate = 0.2;
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;

        $basket = $this->tebexClient()->addPackage($ident, 'vip-rank', 2);

        $this->assertSame('20.00', $basket->basePrice->toDecimal());
        $this->assertSame('4.00', $basket->salesTax->toDecimal());
        $this->assertSame('24.00', $basket->totalPrice->toDecimal());
        $this->assertSame([['id' => 50001, 'name' => 'VIP', 'quantity' => 2, 'price' => '10']], $basket->packages);

        $call = $tebex->calls('add')[0];
        $this->assertSame("/api/baskets/{$ident}/packages", $call['path']);
        $this->assertSame(['package_id' => 'vip-rank', 'quantity' => 2], $call['body']);
    }

    #[Test]
    public function a_package_tebex_does_not_know_is_refused(): void
    {
        $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;

        try {
            $this->tebexClient()->addPackage($ident, 'no-such-package', 1);
            $this->fail('A package Tebex does not know was accepted.');
        } catch (TebexException $exception) {
            $this->assertSame(400, $exception->status);
            $this->assertStringContainsString('not one in this store', $exception->getMessage());
        }
    }

    #[Test]
    public function a_package_is_taken_out_and_the_basket_read_again(): void
    {
        $this->vip();
        $tebex = $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;
        $this->tebexClient()->addPackage($ident, 'vip-rank', 1);

        $this->assertCount(1, $this->tebexClient()->basket($ident)->packages);

        $emptied = $this->tebexClient()->removePackage($ident, '50001');

        $this->assertSame([], $emptied->packages);
        $this->assertSame([], $this->tebexClient()->basket($ident)->packages);
        $this->assertSame("/api/baskets/{$ident}/packages/remove", $tebex->calls('remove')[0]['path']);
        $this->assertSame(['package_id' => '50001'], $tebex->calls('remove')[0]['body']);
        $this->assertSame("/api/accounts/{$this->tebexToken}/baskets/{$ident}", $tebex->calls('get')[1]['path']);
    }

    // --- Who the buyer is ---------------------------------------------------------------------------------------

    #[Test]
    public function a_username_is_sent_when_the_basket_is_made_and_tebex_says_what_it_recognised_it_as(): void
    {
        $tebex = $this->fakeTebexBaskets();

        $basket = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null, 'Notch');

        $this->assertSame('Notch', $tebex->calls('create')[0]['body']['username']);
        $this->assertSame('Notch', $basket->username);
        $this->assertGreaterThan(0, $basket->usernameId);
        $this->assertTrue($basket->isAuthorised());
    }

    #[Test]
    public function a_basket_nobody_has_signed_in_to_is_not_authorised(): void
    {
        $this->fakeTebexBaskets();

        $basket = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null);

        $this->assertNull($basket->usernameId, 'Tebex says 0, which is nobody');
        $this->assertNull($basket->username);
        $this->assertFalse($basket->isAuthorised());
    }

    #[Test]
    public function a_username_tebex_does_not_recognise_gives_a_basket_that_is_not_authorised(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $tebex->unknownUsernames[] = 'Nobody';

        $this->assertFalse($this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null, 'Nobody')->isAuthorised());
    }

    #[Test]
    public function what_a_package_needs_to_be_delivered_goes_in_with_it_and_nothing_is_sent_when_there_is_none(): void
    {
        $this->vip();
        $tebex = $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;

        $this->tebexClient()->addPackage($ident, 'vip-rank', 1, ['username_id' => 76561198000000001]);
        $this->tebexClient()->addPackage($ident, 'vip-rank', 1);
        $this->tebexClient()->addPackage($ident, 'vip-rank', 1, []);

        [$with, $without, $empty] = array_column($tebex->calls('add'), 'body');
        $this->assertSame(['package_id' => 'vip-rank', 'quantity' => 1, 'variable_data' => ['username_id' => 76561198000000001]], $with);
        $this->assertSame(['package_id' => 'vip-rank', 'quantity' => 1], $without);
        $this->assertSame(['package_id' => 'vip-rank', 'quantity' => 1], $empty);
    }

    #[Test]
    public function the_ways_to_sign_in_are_asked_for_with_where_to_come_back_to(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $tebex->signInOptions = [['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam'], ['name' => 'Discord', 'url' => 'https://auth.tebex.test/discord']];
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;

        $options = $this->tebexClient()->signInOptions($ident, 'https://shop.test/back?x=1&y=2');

        $this->assertSame([['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam'], ['name' => 'Discord', 'url' => 'https://auth.tebex.test/discord']], $options);
        $call = $tebex->calls('auth')[0];
        $this->assertSame("/api/accounts/{$this->tebexToken}/baskets/{$ident}/auth", $call['path']);
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        $this->assertSame('https://shop.test/back?x=1&y=2', $query['returnUrl']);
        $this->assertArrayNotHasKey('Authorization', $call['headers']);
    }

    #[Test]
    public function the_ways_to_sign_in_are_read_whether_or_not_tebex_wraps_them(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['data' => [['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam']]])]);

        $this->assertSame([['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam']], $this->tebexClient()->signInOptions('bsk000001', 'https://shop.test/back'));
    }

    #[Test]
    public function a_way_to_sign_in_that_is_not_a_web_address_or_has_no_name_is_handled(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([
            ['name' => 'Evil', 'url' => 'javascript:alert(1)'],
            ['name' => 'No address'],
            'not an object',
            ['url' => 'https://auth.tebex.test/anon'],
        ])]);

        $this->assertSame([['name' => 'Sign in', 'url' => 'https://auth.tebex.test/anon']], $this->tebexClient()->signInOptions('bsk000001', 'https://shop.test/back'));
    }

    #[Test]
    public function a_sign_in_answer_that_is_not_a_list_is_refused(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam'])]);

        $this->expectException(TebexException::class);
        $this->expectExceptionMessage('sign-in answer the shop could not understand');

        $this->tebexClient()->signInOptions('bsk000001', 'https://shop.test/back');
    }

    #[Test]
    public function asking_for_the_ways_to_sign_in_is_repeated_after_a_server_error_because_it_changes_nothing(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;
        $tebex->failOn('auth', 503, 2);

        $this->assertCount(1, $this->tebexClient()->signInOptions($ident, 'https://shop.test/back'));
        $this->assertCount(3, $tebex->calls('auth'));
    }

    #[Test]
    public function a_paid_basket_offers_a_payment_instead_of_a_way_to_pay(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;
        $tebex->markPaid($ident);

        $basket = $this->tebexClient()->basket($ident);

        $this->assertTrue($basket->isPaid());
        $this->assertNull($basket->checkoutUrl);
        $this->assertSame("https://pay.tebex.io/{$ident}/payment", $basket->paymentUrl);
    }

    // --- What may be repeated ------------------------------------------------------------------------------

    #[Test]
    public function making_a_basket_is_not_repeated_after_a_server_error_because_it_may_have_been_made(): void
    {
        $tebex = $this->fakeTebexBaskets()->failOn('create', 503, 3);

        try {
            $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null);
            $this->fail('A failing call was reported as working.');
        } catch (TebexException $exception) {
            $this->assertSame(503, $exception->status);
        }

        $this->assertCount(1, $tebex->calls('create'));
    }

    #[Test]
    public function adding_a_package_is_not_repeated_after_a_network_failure_because_it_may_have_been_added(): void
    {
        $attempts = 0;
        $this->fakeTebexHttp(['headless.tebex.io/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->expectException(TebexException::class);

        try {
            $this->tebexClient()->addPackage('bsk000001', 'vip-rank', 1);
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    #[Test]
    public function a_call_that_was_refused_for_asking_too_often_is_repeated_because_it_was_not_made(): void
    {
        $tebex = $this->fakeTebexBaskets()->failOn('create', 429, 2);

        $basket = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null);

        $this->assertStringStartsWith('bsk', $basket->ident);
        $this->assertCount(3, $tebex->calls('create'));
    }

    #[Test]
    public function reading_a_basket_is_repeated_after_a_server_error(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $ident = $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null)->ident;
        $tebex->failOn('get', 503, 2);

        $this->assertSame($ident, $this->tebexClient()->basket($ident)->ident);
        $this->assertCount(3, $tebex->calls('get'));
    }

    #[Test]
    public function an_answer_that_is_not_a_basket_is_refused(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['data' => ['complete' => false, 'packages' => []]])]);

        $this->expectException(TebexException::class);
        $this->expectExceptionMessage('basket the shop could not understand');

        $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null);
    }

    #[Test]
    public function the_token_is_needed_for_a_basket_as_for_anything_else(): void
    {
        Http::fake();
        config(['tebex.public_token' => null]);

        try {
            $this->tebexClient()->createBasket('https://shop.test/done', 'https://shop.test/cart', [], null);
            $this->fail('A call went out with no token.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('TEBEX_PUBLIC_TOKEN', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
