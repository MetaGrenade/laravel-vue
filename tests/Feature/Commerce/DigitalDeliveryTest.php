<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderFulfilled;
use App\Models\DownloadCount;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductFile;
use App\Notifications\OrderConfirmation;
use App\Notifications\OrderShipped;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\Digital\DigitalFulfilment;
use App\Support\Commerce\Digital\DownloadDelivery;
use App\Support\Commerce\Digital\DownloadRefused;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What a paid order gives a customer in digital goods: the right to download, the signed links the
 * order page hands out, and everything that can stop a download.
 */
class DigitalDeliveryTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['commerce.downloads.disk' => 'local', 'commerce.downloads.limit' => 10, 'commerce.downloads.expires_after_days' => 0]);
        $this->setUpCommerce();
    }

    private function digitalProduct(string $name = 'Ebook', string $contents = 'the file bytes'): array
    {
        $product = Product::factory()->priced('15.00')->digital()->create(['name' => $name]);
        $file = ProductFile::factory()->for($product)->withContents($contents)->create(['name' => "{$name} (PDF)", 'original_name' => 'ebook.pdf']);

        return [$product, $file];
    }

    /**
     * Buy the product as the current guest, and pay.
     *
     * @return array{0: Order, 1: DownloadGrant|null}
     */
    private function buy(Product $product, int $quantity = 1, bool $pay = true): array
    {
        $this->cartWith($product, $quantity);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        if ($pay) {
            $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        }

        return [$order->refresh(), DownloadGrant::query()->where('order_id', $order->id)->first()];
    }

    private function orderPage(Order $order): TestResponse
    {
        return $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }

    private function linkTo(DownloadGrant $grant, ProductFile $file): string
    {
        return app(DownloadDelivery::class)->link($grant, $file);
    }

    private function downloads(DownloadGrant $grant, ProductFile $file): int
    {
        return (int) DownloadCount::query()->where('download_grant_id', $grant->id)->where('product_file_id', $file->id)->value('downloads');
    }

    // --- Granting ----------------------------------------------------------------------------------

    #[Test]
    public function a_paid_order_gives_the_right_to_download_each_line_that_has_files(): void
    {
        [$ebook] = $this->digitalProduct('Ebook');
        [$order, $grant] = $this->buy($ebook);

        $this->assertNotNull($grant);
        $this->assertSame($order->items->sole()->id, $grant->order_item_id);
        $this->assertSame($ebook->id, $grant->product_id);
        $this->assertNull($grant->expires_at);
        $this->assertTrue($grant->isUsable());
        $this->assertSame(26, strlen($grant->public_id), 'an unguessable id, not the row id');
    }

    #[Test]
    public function nothing_is_granted_until_the_order_is_paid(): void
    {
        [$ebook] = $this->digitalProduct();

        [$order, $grant] = $this->buy($ebook, pay: false);

        $this->assertNull($grant);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    #[Test]
    public function a_line_whose_product_has_no_files_gets_no_grant(): void
    {
        $plain = Product::factory()->priced('15.00')->digital()->create();

        [$order, $grant] = $this->buy($plain);

        $this->assertNull($grant);
        $this->assertSame(OrderStatus::Processing, $order->status, 'nothing delivers it, so a person still has to (a service, a licence sent by hand)');
    }

    #[Test]
    public function granting_twice_changes_nothing(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        $created = app(DigitalFulfilment::class)->grant($order);

        $this->assertSame(0, $created);
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count());
        $this->assertSame($grant->id, DownloadGrant::query()->where('order_id', $order->id)->value('id'));
    }

    #[Test]
    public function a_redelivered_payment_message_does_not_grant_again(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order] = $this->buy($ebook);

        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole()));
        $this->deliverStripeEvent($event)->assertOk();

        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function the_access_can_expire_a_set_time_after_payment(): void
    {
        config(['commerce.downloads.expires_after_days' => 30]);
        [$ebook] = $this->digitalProduct();

        [, $grant] = $this->buy($ebook);

        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $grant->expires_at->timestamp, 5);
    }

    #[Test]
    public function a_file_added_later_reaches_people_who_already_bought(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        $bonus = ProductFile::factory()->for($ebook)->withContents('bonus')->create(['name' => 'Bonus chapter', 'position' => 5]);

        $this->get($this->linkTo($grant, $bonus))->assertOk();
    }

    // --- Nothing to ship ---------------------------------------------------------------------------

    #[Test]
    public function an_order_made_only_of_digital_goods_is_complete_as_soon_as_it_is_paid(): void
    {
        Notification::fake();
        [$ebook] = $this->digitalProduct();

        [$order] = $this->buy($ebook);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->fulfilled_at);
        $this->assertSame([OrderEvent::PAID, OrderEvent::FULFILLED], $order->events()->orderBy('id')->pluck('type')->all());
    }

    #[Test]
    public function a_digital_order_does_not_send_a_shipping_email_but_does_send_the_receipt(): void
    {
        Notification::fake();
        [$ebook] = $this->digitalProduct();

        $this->buy($ebook);

        Notification::assertSentOnDemand(OrderConfirmation::class);
        Notification::assertNotSentTo(new AnonymousNotifiable, OrderShipped::class);
    }

    #[Test]
    public function an_order_of_downloads_and_something_that_is_not_delivered_by_download_stays_open(): void
    {
        [$ebook] = $this->digitalProduct();
        $service = Product::factory()->priced('30.00')->digital()->create(['name' => 'Setup call']);
        $cart = $this->cartWith($ebook);
        CartManager::addItem($cart, $service, null, $service->prices()->first(), 1);

        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function an_order_with_something_to_ship_stays_open_even_if_part_of_it_is_digital(): void
    {
        [$ebook] = $this->digitalProduct();
        $mug = Product::factory()->priced('10.00')->stocked(5)->create(['name' => 'Mug']);
        $cart = $this->cartWith($ebook);
        CartManager::addItem($cart, $mug, null, $mug->prices()->first(), 1);

        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        $order->refresh();

        $this->assertSame(OrderStatus::Processing, $order->status, 'the mug still has to go out');
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count(), 'but the ebook can be downloaded now');
        $this->assertSame([true, false], $order->items()->orderBy('id')->pluck('requires_shipping')->map(fn ($shipped) => (bool) $shipped)->sort()->values()->reverse()->values()->all());
    }

    #[Test]
    public function each_order_line_remembers_whether_it_had_to_be_shipped(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order] = $this->buy($ebook);

        $this->assertFalse($order->items->sole()->requires_shipping);

        // Even if the product is changed afterwards.
        $ebook->update(['requires_shipping' => true]);
        $this->assertFalse($order->fresh('items')->items->sole()->requires_shipping);
    }

    #[Test]
    public function completing_a_digital_order_tells_listeners_not_to_email_the_customer(): void
    {
        Event::fake([OrderFulfilled::class]);
        [$ebook] = $this->digitalProduct();

        $this->buy($ebook);

        Event::assertDispatched(OrderFulfilled::class, fn (OrderFulfilled $event) => $event->notifyCustomer === false);
    }

    // --- The order page ----------------------------------------------------------------------------

    #[Test]
    public function the_order_page_lists_the_files_with_signed_links(): void
    {
        [$ebook, $file] = $this->digitalProduct('Ebook', 'twelve bytes');
        [$order, $grant] = $this->buy($ebook);

        $this->orderPage($order)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('downloads', 1)
            ->where('downloads.0.description', 'Ebook')
            ->where('downloads.0.status', 'active')
            ->where('downloads.0.files.0.name', 'Ebook (PDF)')
            ->where('downloads.0.files.0.size', 12)
            ->where('downloads.0.files.0.sha256', hash('sha256', 'twelve bytes'))
            ->where('downloads.0.files.0.remaining', 10)
            ->where('downloads.0.files.0.state', 'ready')
            ->where('downloads.0.files.0.url', fn (string $url) => str_contains($url, $grant->public_id) && str_contains($url, 'signature=')));
    }

    #[Test]
    public function the_files_real_location_is_never_sent_to_the_browser(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order] = $this->buy($ebook);

        $props = json_encode($this->orderPage($order)->viewData('page')['props']);

        $this->assertStringNotContainsString($file->path, $props);
        $this->assertStringNotContainsString('product-files', $props);
        $this->assertStringNotContainsString('original_name', $props);
    }

    #[Test]
    public function an_order_without_files_shows_no_downloads(): void
    {
        [$order] = $this->placeOrder();

        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('downloads', [])->where('downloadsPending', false));
    }

    #[Test]
    public function an_unpaid_order_says_the_downloads_will_appear(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order] = $this->buy($ebook, pay: false);

        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('downloads', [])->where('downloadsPending', true));
    }

    #[Test]
    public function the_receipt_email_points_to_the_order_page_for_the_downloads(): void
    {
        Notification::fake();
        [$ebook] = $this->digitalProduct();

        $this->buy($ebook);

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $mail = $notification->toMail(new AnonymousNotifiable);

            return collect($mail->introLines)->contains(fn (string $line) => str_contains($line, 'Your downloads are on your order page'))
                && str_contains((string) $mail->actionUrl, 'signature=')
                && ! str_contains(json_encode($mail->introLines), '/downloads/');
        });
    }

    #[Test]
    public function an_email_for_an_order_without_downloads_does_not_mention_them(): void
    {
        Notification::fake();
        $this->placePaidOrder();

        Notification::assertSentOnDemand(OrderConfirmation::class, fn (OrderConfirmation $notification) => ! str_contains(
            json_encode($notification->toMail(new AnonymousNotifiable)->introLines),
            'downloads',
        ));
    }

    // --- Downloading -------------------------------------------------------------------------------

    #[Test]
    public function the_signed_link_delivers_the_file_as_an_attachment(): void
    {
        [$ebook, $file] = $this->digitalProduct('Ebook', 'the real contents');
        [$order, $grant] = $this->buy($ebook);

        $response = $this->get($this->linkTo($grant, $file));

        $response->assertOk();
        $this->assertSame('the real contents', $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('ebook.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_file_is_never_shown_inline_whatever_it_claims_to_be(): void
    {
        $product = Product::factory()->priced('5.00')->digital()->create();
        $file = ProductFile::factory()->for($product)->withContents('<script>alert(1)</script>')->create(['original_name' => 'page.html', 'mime' => 'text/html']);
        [, $grant] = $this->buy($product);

        $response = $this->get($this->linkTo($grant, $file));

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function every_download_is_counted_and_the_page_shows_what_is_left(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        $this->get($this->linkTo($grant, $file))->assertOk();
        $this->get($this->linkTo($grant, $file))->assertOk();

        $this->assertSame(2, $this->downloads($grant, $file));
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('downloads.0.files.0.remaining', 8));
    }

    #[Test]
    public function a_file_stops_after_the_most_downloads_allowed(): void
    {
        config(['commerce.downloads.limit' => 2]);
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        $this->get($this->linkTo($grant, $file))->assertOk();
        $this->get($this->linkTo($grant, $file))->assertOk();
        $this->get($this->linkTo($grant, $file))->assertForbidden();

        $this->assertSame(2, $this->downloads($grant, $file), 'the refused request did not count');
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page
            ->where('downloads.0.files.0.remaining', 0)
            ->where('downloads.0.files.0.state', 'used_up')
            ->where('downloads.0.files.0.url', null));
    }

    #[Test]
    public function the_limit_is_for_each_file_not_shared_between_them(): void
    {
        config(['commerce.downloads.limit' => 1]);
        [$ebook, $first] = $this->digitalProduct();
        $second = ProductFile::factory()->for($ebook)->withContents('two')->create(['name' => 'Second']);
        [, $grant] = $this->buy($ebook);

        $this->get($this->linkTo($grant, $first))->assertOk();
        $this->get($this->linkTo($grant, $first))->assertForbidden();
        $this->get($this->linkTo($grant, $second))->assertOk();
    }

    #[Test]
    public function a_limit_of_zero_means_no_limit(): void
    {
        config(['commerce.downloads.limit' => 0]);
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        foreach (range(1, 15) as $attempt) {
            $this->get($this->linkTo($grant, $file))->assertOk();
        }

        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('downloads.0.files.0.remaining', null));
    }

    #[Test]
    public function two_orders_for_the_same_product_each_have_their_own_allowance(): void
    {
        config(['commerce.downloads.limit' => 1]);
        [$ebook, $file] = $this->digitalProduct();
        [, $first] = $this->buy($ebook);
        $this->get($this->linkTo($first, $file))->assertOk();

        [, $second] = $this->buy($ebook);

        $this->get($this->linkTo($second, $file))->assertOk();
    }

    #[Test]
    public function the_last_download_goes_to_only_one_of_two_requests(): void
    {
        // The count is raised by one conditional update, so two requests cannot both take the last one.
        config(['commerce.downloads.limit' => 1]);
        [$ebook, $file] = $this->digitalProduct();
        [, $grant] = $this->buy($ebook);
        $delivery = app(DownloadDelivery::class);

        $delivery->claim($grant->fresh(), $file);

        $this->expectException(DownloadRefused::class);
        $delivery->claim($grant->fresh(), $file);
    }

    // --- Things that stop a download ---------------------------------------------------------------

    #[Test]
    public function a_link_without_a_signature_is_refused(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [, $grant] = $this->buy($ebook);

        $this->get(route('shop.downloads.show', ['grant' => $grant->public_id, 'file' => $file->id]))->assertForbidden();

        $this->assertSame(0, $this->downloads($grant, $file));
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        $other = ProductFile::factory()->for($ebook)->withContents('other')->create();
        [, $grant] = $this->buy($ebook);

        // The signature belongs to the first file: pointing it at another does not work.
        $url = str_replace('/'.$file->id.'?', '/'.$other->id.'?', $this->linkTo($grant, $file));

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_link_stops_working_after_a_while(): void
    {
        config(['commerce.downloads.link_minutes' => 30]);
        [$ebook, $file] = $this->digitalProduct();
        [, $grant] = $this->buy($ebook);
        $url = $this->linkTo($grant, $file);

        $this->travel(29)->minutes();
        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_grant_cannot_download_a_file_of_another_product(): void
    {
        [$ebook] = $this->digitalProduct('Ebook');
        [, $otherFile] = $this->digitalProduct('Other');
        [, $grant] = $this->buy($ebook);

        $this->get($this->linkTo($grant, $otherFile))->assertNotFound();
    }

    #[Test]
    public function a_file_that_is_switched_off_cannot_be_downloaded_and_costs_nothing(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);
        $file->update(['is_active' => false]);

        $this->get($this->linkTo($grant, $file))->assertNotFound();

        $this->assertSame(0, $this->downloads($grant, $file));
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('downloads.0.files.0.state', 'unavailable')->where('downloads.0.files.0.url', null));
    }

    #[Test]
    public function a_file_that_has_gone_missing_from_the_disk_does_not_use_up_a_download(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [, $grant] = $this->buy($ebook);
        Storage::disk('local')->delete($file->path);

        $this->get($this->linkTo($grant, $file))->assertNotFound();

        $this->assertSame(0, $this->downloads($grant, $file));
    }

    #[Test]
    public function access_ends_when_it_expires(): void
    {
        config(['commerce.downloads.expires_after_days' => 30]);
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        $this->travel(29)->days();
        $this->get($this->linkTo($grant, $file))->assertOk();

        $this->travel(2)->days();
        $this->get($this->linkTo($grant, $file))->assertStatus(410);
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page
            ->where('downloads.0.status', 'expired')
            ->where('downloads.0.files.0.url', null));
    }

    #[Test]
    public function an_order_that_is_not_paid_gives_nothing(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);
        // A grant that somehow exists for an order that is not paid (it was cancelled and reset).
        $order->forceFill(['payment_status' => OrderPaymentStatus::Unpaid])->save();

        $this->get($this->linkTo($grant, $file))->assertForbidden();
    }

    #[Test]
    public function downloads_are_rate_limited_for_each_order(): void
    {
        config(['commerce.downloads.limit' => 0]);
        [$ebook, $file] = $this->digitalProduct();
        [, $grant] = $this->buy($ebook);
        $url = $this->linkTo($grant, $file);

        foreach (range(1, 20) as $attempt) {
            $this->get($url)->assertOk();
        }

        $this->get($url)->assertStatus(429);
    }

    // --- Refunds -----------------------------------------------------------------------------------

    private function refundInFull(Order $order): void
    {
        Notification::fake();

        $this->app->make(OrderRefunder::class)->request(
            $order->refresh(),
            Money::parse($order->grand_total, 'USD'),
            new RefundRequest(token: (string) Str::uuid()),
        );
    }

    #[Test]
    public function a_refund_in_full_ends_the_downloads(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);
        $this->get($this->linkTo($grant, $file))->assertOk();

        $this->refundInFull($order);

        $grant->refresh();
        $this->assertSame(DownloadGrant::REVOKED, $grant->status());
        $this->assertSame(DownloadGrant::BY_REFUND, $grant->revoked_reason);
        $this->get($this->linkTo($grant, $file))->assertForbidden();
    }

    #[Test]
    public function a_partial_refund_leaves_the_downloads(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);

        Notification::fake();
        $this->app->make(OrderRefunder::class)->request($order->refresh(), Money::parse('5.00', 'USD'), new RefundRequest(token: (string) Str::uuid()));

        $this->assertTrue($grant->fresh()->isUsable());
        $this->get($this->linkTo($grant, $file))->assertOk();
    }

    #[Test]
    public function the_downloads_come_back_if_the_refund_that_ended_them_fails(): void
    {
        [$ebook, $file] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);
        $this->refundInFull($order);
        $this->assertFalse($grant->fresh()->isUsable());

        // Stripe says the refund did not go through after all.
        $failed = $this->stripe->setRefundStatus('re_test_1', 'failed', 'expired_or_canceled_card');
        $this->deliverStripeEvent($this->stripeEvent('refund.failed', $failed))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertTrue($grant->fresh()->isUsable(), 'the money was never returned');
        $this->get($this->linkTo($grant->fresh(), $file))->assertOk();
    }

    #[Test]
    public function a_refund_does_not_undo_access_staff_took_away(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order, $grant] = $this->buy($ebook);
        $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => DownloadGrant::BY_STAFF])->save();

        // A partial refund recalculates the order, which restores only what a refund took away.
        Notification::fake();
        $this->app->make(OrderRefunder::class)->request($order->refresh(), Money::parse('5.00', 'USD'), new RefundRequest(token: (string) Str::uuid()));

        $this->assertSame(DownloadGrant::BY_STAFF, $grant->fresh()->revoked_reason);
        $this->assertFalse($grant->fresh()->isUsable());
    }

    // --- The lifecycle itself ----------------------------------------------------------------------

    #[Test]
    public function an_order_can_only_be_paid_for_nothing_when_it_costs_nothing(): void
    {
        [$ebook] = $this->digitalProduct();
        [$order] = $this->buy($ebook, pay: false);

        $this->assertFalse(app(OrderLifecycle::class)->markFree($order));
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
