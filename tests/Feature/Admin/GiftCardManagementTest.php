<?php

namespace Tests\Feature\Admin;

use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Commerce\GiftCards\GiftCards;
use App\Support\Commerce\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * Staff making and looking after gift cards.
 */
class GiftCardManagementTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private const CODE = 'ABCD-EFGH-JKLM-NPQR';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        return $viewer;
    }

    private function issued(string $amount = '50.00', ?User $by = null): GiftCard
    {
        return app(GiftCards::class)->issue(Money::parse($amount, 'USD'), $by);
    }

    private function usd(string $amount): Money
    {
        return Money::parse($amount, 'USD');
    }

    // --- Access ------------------------------------------------------------------------------------

    #[Test]
    public function the_pages_need_a_signed_in_user_with_commerce_access(): void
    {
        $card = $this->issued();

        $this->get(route('acp.commerce.gift-cards.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.gift-cards.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.gift-cards.show', $card))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('acp.commerce.gift-cards.store'), ['amount' => '10'])->assertForbidden();
    }

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $card = $this->issued();
        $viewer = $this->viewer();

        // Looking is allowed; nothing else is.
        $this->actingAs($viewer)->get(route('acp.commerce.gift-cards.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.commerce.gift-cards.show', $card))->assertOk();
        $this->actingAs($viewer)->get(route('acp.commerce.gift-cards.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.gift-cards.store'), ['amount' => '10'])->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'add', 'amount' => '5', 'note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.gift-cards.regenerate', $card))->assertForbidden();
        $this->actingAs($viewer)->patch(route('acp.commerce.gift-cards.update', $card), ['is_active' => false])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.gift-cards.destroy', $card))->assertForbidden();

        // Editing switches a card on or off, and nothing that makes value.
        $viewer->givePermissionTo('commerce.acp.edit');
        $this->actingAs($viewer)->patch(route('acp.commerce.gift-cards.update', $card), ['is_active' => false])->assertRedirect();
        $this->actingAs($viewer)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'add', 'amount' => '5', 'note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.gift-cards.destroy', $card))->assertForbidden();

        // Making value is the create permission, deleting is its own.
        $viewer->givePermissionTo('commerce.acp.create');
        $this->actingAs($viewer)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'add', 'amount' => '5', 'note' => 'Goodwill'])->assertRedirect();
        $this->actingAs($viewer)->delete(route('acp.commerce.gift-cards.destroy', $card))->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.delete');
        $this->actingAs($viewer)->delete(route('acp.commerce.gift-cards.destroy', $card))->assertRedirect(route('acp.commerce.gift-cards.index'));
        $this->assertSame(0, GiftCard::query()->count());
    }

    #[Test]
    public function the_shop_section_being_off_hides_the_pages(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.index'))->assertNotFound();
    }

    // --- Making a card -----------------------------------------------------------------------------

    #[Test]
    public function a_card_is_made_and_its_code_is_handed_over_once(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('acp.commerce.gift-cards.store'), [
            'amount' => '25.00',
            'expires_at' => now()->addMonths(6)->toDateString(),
            'note' => 'A thank-you for Ada',
        ]);

        $card = GiftCard::query()->sole();
        $response->assertRedirect(route('acp.commerce.gift-cards.show', $card))->assertSessionHas('success');
        $code = session('gift_card_code');

        $this->assertMatchesRegularExpression('/^[A-Z2-9-]{19}$/', $code);
        $this->assertSame($card->id, GiftCard::findByCode($code)?->id, 'the code it was given works');
        $this->assertSame('25.00', $card->balance);
        $this->assertSame($admin->id, $card->issued_by);
        $this->assertSame('A thank-you for Ada', $card->note);
        $this->assertTrue($card->expires_at->isSameDay(now()->addMonths(6)));
        $this->assertSame('23:59:59', $card->expires_at->format('H:i:s'), 'good through the end of the day it names');
    }

    #[Test]
    public function the_code_is_on_the_page_that_made_it_and_on_no_other_visit(): void
    {
        $admin = $this->admin();
        $card = $this->issued();

        // What the request that made the card flashed for the next one: there for this request, gone after it.
        $this->withSession(['_flash' => ['old' => ['gift_card_code'], 'new' => []], 'gift_card_code' => 'ABCD-EFGH-JKLM-NPQR']);
        $this->actingAs($admin)->get(route('acp.commerce.gift-cards.show', $card))
            ->assertInertia(fn (Assert $page) => $page->where('code', 'ABCD-EFGH-JKLM-NPQR'));

        // Any other time, there is nothing to show: it is not kept.
        $this->actingAs($admin)->get(route('acp.commerce.gift-cards.show', $card))
            ->assertInertia(fn (Assert $page) => $page->where('code', null));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badCards(): array
    {
        return [
            'no amount' => [['amount' => ''], 'amount'],
            'zero' => [['amount' => '0'], 'amount'],
            'less than nothing' => [['amount' => '-5'], 'amount'],
            'not a number' => [['amount' => 'lots'], 'amount'],
            'a fraction of a cent' => [['amount' => '10.005'], 'amount'],
            'too big' => [['amount' => '100000000'], 'amount'],
            'expiring today' => [['amount' => '10', 'expires_at' => '2000-01-01'], 'expires_at'],
            'not a date' => [['amount' => '10', 'expires_at' => 'soon'], 'expires_at'],
            'a note that is too long' => [['amount' => '10', 'note' => 'x'], 'note'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('badCards')]
    public function a_card_has_to_be_a_sensible_one(array $payload, string $field): void
    {
        if ($field === 'note') {
            $payload['note'] = str_repeat('x', 501);
        }

        $this->actingAs($this->admin())->post(route('acp.commerce.gift-cards.store'), $payload)->assertSessionHasErrors($field);

        $this->assertSame(0, GiftCard::query()->count());
    }

    // --- The list ----------------------------------------------------------------------------------

    #[Test]
    public function the_list_shows_each_card_by_the_end_of_its_code_and_never_by_the_code(): void
    {
        $card = $this->issued('40.00');
        GiftCard::query()->whereKey($card->id)->update(['note' => 'Ada']);

        $response = $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/CommerceGiftCards')
            ->where('cards.data.0.masked', '••••-••••-••••-'.$card->last_four)
            ->where('cards.data.0.balance', '40.00')
            ->where('cards.data.0.status', 'active')
            ->where('cards.data.0.note', 'Ada')
            ->missing('cards.data.0.code_hash'));
        $this->assertStringNotContainsString($card->fresh()->code_hash, $response->getContent());
        $this->assertStringNotContainsString((string) $card->plainCode, $response->getContent());
    }

    #[Test]
    public function the_list_can_be_narrowed_by_state_and_each_state_is_counted(): void
    {
        $admin = $this->admin();
        GiftCard::factory()->worth('10.00')->create();
        GiftCard::factory()->worth('20.00')->expiring()->create();
        GiftCard::factory()->spent()->create();
        GiftCard::factory()->expired()->create();
        GiftCard::factory()->inactive()->create();
        GiftCard::factory()->inactive()->expired()->spent()->create();

        $count = fn (string $status) => count($this->actingAs($admin)->get(route('acp.commerce.gift-cards.index', ['status' => $status]))->viewData('page')['props']['cards']['data']);

        $this->assertSame(2, $count('active'));
        $this->assertSame(1, $count('used_up'));
        $this->assertSame(1, $count('expired'));
        $this->assertSame(2, $count('disabled'), 'switched off beats everything else');
        $this->assertSame(6, $count('all'));
        $this->assertSame(6, $count('nonsense'), 'an unknown state is no filter');

        $this->actingAs($admin)->get(route('acp.commerce.gift-cards.index'))->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['all' => 6, 'active' => 2, 'used_up' => 1, 'expired' => 1, 'disabled' => 2]));
    }

    #[Test]
    public function the_list_says_what_the_shop_owes_on_cards_that_can_still_be_spent(): void
    {
        GiftCard::factory()->worth('10.00')->create();
        GiftCard::factory()->worth('25.50')->expiring()->create();
        GiftCard::factory()->worth('99.00')->inactive()->create();
        GiftCard::factory()->worth('99.00')->expired()->create();
        GiftCard::factory()->spent()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.index'))->assertInertia(fn (Assert $page) => $page
            ->where('outstanding', '35.50'));
    }

    #[Test]
    public function a_card_is_found_by_its_whole_code_its_last_four_characters_or_its_note(): void
    {
        $admin = $this->admin();
        $wanted = GiftCard::factory()->code(self::CODE)->create(['note' => 'For the Lovelace family']);
        GiftCard::factory()->count(2)->create();

        $found = fn (string $search) => collect($this->actingAs($admin)->get(route('acp.commerce.gift-cards.index', ['search' => $search]))->viewData('page')['props']['cards']['data'])->pluck('id')->all();

        $this->assertSame([$wanted->id], $found('abcd efgh jklm npqr'), 'the whole code, as a customer would read it out');
        $this->assertSame([$wanted->id], $found('npqr'), 'the last four');
        $this->assertSame([$wanted->id], $found('lovelace'), 'the note');
        $this->assertSame([], $found('ZZZZ-ZZZZ-ZZZZ-ZZZZ'));
    }

    #[Test]
    public function the_list_is_paged(): void
    {
        GiftCard::factory()->count(27)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.index'))->assertInertia(fn (Assert $page) => $page
            ->has('cards.data', 25)
            ->where('cards.meta.total', 27)
            ->where('cards.meta.last_page', 2));
    }

    // --- One card ----------------------------------------------------------------------------------

    #[Test]
    public function a_card_shows_its_history_and_whether_that_agrees_with_its_balance(): void
    {
        $staff = User::factory()->create(['nickname' => 'Grace']);
        $card = $this->issued('50.00', $staff);
        app(GiftCards::class)->adjust($card, $this->usd('-5.00'), 'Sent to the wrong person', $staff);

        $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.show', $card))->assertInertia(fn (Assert $page) => $page
            ->component('acp/CommerceGiftCardShow')
            ->where('card.balance', '45.00')
            ->where('card.ledger_matches', true)
            ->where('card.orders', 0)
            ->where('ledger.data.0.type', 'adjusted')
            ->where('ledger.data.0.amount', '-5.00')
            ->where('ledger.data.0.balance_after', '45.00')
            ->where('ledger.data.0.by', 'Grace')
            ->where('ledger.data.0.note', 'Sent to the wrong person')
            ->where('ledger.data.1.type', 'issued')
            ->where('deletable', true));

        // Something that changed the balance without leaving a trace is shown for what it is.
        GiftCard::query()->whereKey($card->id)->update(['balance' => '99.00']);
        $this->actingAs($this->admin())->get(route('acp.commerce.gift-cards.show', $card))
            ->assertInertia(fn (Assert $page) => $page->where('card.ledger_matches', false));
    }

    // --- Changing a card ---------------------------------------------------------------------------

    #[Test]
    public function a_card_can_be_switched_off_and_given_a_date_and_a_note(): void
    {
        $card = $this->issued();

        $this->actingAs($this->admin())->patch(route('acp.commerce.gift-cards.update', $card), [
            'is_active' => false,
            'expires_at' => '2031-03-15',
            'note' => 'Reported lost',
        ])->assertRedirect()->assertSessionHas('success');

        $card->refresh();
        $this->assertFalse($card->is_active);
        $this->assertSame('2031-03-15 23:59:59', $card->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('Reported lost', $card->note);
        $this->assertSame('50.00', $card->balance, 'the balance is untouched');
        $this->assertSame(GiftCard::DISABLED, $card->status());

        $this->actingAs($this->admin())->patch(route('acp.commerce.gift-cards.update', $card), ['is_active' => true, 'expires_at' => '', 'note' => ''])->assertRedirect();

        $card->refresh();
        $this->assertTrue($card->is_active);
        $this->assertNull($card->expires_at);
        $this->assertNull($card->note);
    }

    #[Test]
    public function a_switched_off_card_cannot_be_put_on_a_cart(): void
    {
        $card = GiftCard::factory()->code(self::CODE)->worth('20.00')->create();
        $product = Product::factory()->priced('50.00')->stocked(5)->create();
        $this->cartWith($product, 1);

        $this->actingAs($this->admin())->patch(route('acp.commerce.gift-cards.update', $card), ['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE])->assertSessionHasErrors('gift_card');
    }

    #[Test]
    public function value_can_be_added_or_taken_away_with_a_reason_that_stays_in_the_history(): void
    {
        $admin = $this->admin();
        $card = $this->issued('50.00');

        $this->actingAs($admin)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'add', 'amount' => '10.50', 'note' => 'Late parcel'])
            ->assertRedirect()->assertSessionHas('success', 'Value added.');
        $this->actingAs($admin)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'remove', 'amount' => '0.50', 'note' => 'Rounding'])
            ->assertRedirect()->assertSessionHas('success', 'Value taken away.');

        $this->assertSame('60.00', $card->fresh()->balance);
        $entries = $card->transactions()->get();
        $this->assertSame(['issued', 'adjusted', 'adjusted'], $entries->pluck('type')->all());
        $this->assertSame(['50.00', '10.50', '-0.50'], $entries->pluck('amount')->all());
        $this->assertSame($admin->id, $entries->last()->user_id);
        $this->assertSame('60.00', app(GiftCards::class)->ledgerBalance($card)->toDecimal());
    }

    #[Test]
    public function taking_value_away_needs_the_edit_permission_as_well_as_create(): void
    {
        $card = $this->issued('50.00');
        $maker = $this->viewer();
        $maker->givePermissionTo('commerce.acp.create');

        // Someone who can create may add value, but not reduce what a customer holds.
        $this->actingAs($maker)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'add', 'amount' => '5', 'note' => 'Goodwill'])->assertRedirect();
        $this->actingAs($maker)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'remove', 'amount' => '55', 'note' => 'Nope'])->assertForbidden();
        $this->assertSame('55.00', $card->fresh()->balance);
        $this->assertSame(['issued', 'adjusted'], $card->transactions()->pluck('type')->all(), 'nothing was written');

        // The page does not offer it either.
        $this->actingAs($maker)->get(route('acp.commerce.gift-cards.show', $card))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('can.edit', false));

        $maker->givePermissionTo('commerce.acp.edit');
        $this->actingAs($maker)->post(route('acp.commerce.gift-cards.adjust', $card), ['direction' => 'remove', 'amount' => '5', 'note' => 'Charged back'])->assertRedirect();
        $this->assertSame('50.00', $card->fresh()->balance);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badAdjustments(): array
    {
        return [
            'no reason' => [['direction' => 'add', 'amount' => '5', 'note' => ''], 'note'],
            'no amount' => [['direction' => 'add', 'amount' => '', 'note' => 'x'], 'amount'],
            'zero' => [['direction' => 'add', 'amount' => '0', 'note' => 'x'], 'amount'],
            'a negative amount (use take away)' => [['direction' => 'add', 'amount' => '-5', 'note' => 'x'], 'amount'],
            'neither adding nor taking away' => [['direction' => 'steal', 'amount' => '5', 'note' => 'x'], 'direction'],
            'more than the card holds' => [['direction' => 'remove', 'amount' => '50.01', 'note' => 'x'], 'amount'],
            'more than a card can hold' => [['direction' => 'add', 'amount' => '99999999.99', 'note' => 'x'], 'amount'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('badAdjustments')]
    public function a_change_of_value_has_to_make_sense(array $payload, string $field): void
    {
        $card = $this->issued('50.00');

        $this->actingAs($this->admin())->post(route('acp.commerce.gift-cards.adjust', $card), $payload)->assertSessionHasErrors($field);

        $this->assertSame('50.00', $card->fresh()->balance);
        $this->assertSame(1, $card->transactions()->count());
    }

    #[Test]
    public function replacing_the_code_hands_over_a_new_one_once_and_kills_the_old(): void
    {
        $card = $this->issued('50.00');
        $old = (string) $card->plainCode;

        $this->actingAs($this->admin())->post(route('acp.commerce.gift-cards.regenerate', $card))
            ->assertRedirect()->assertSessionHas('success');

        $new = session('gift_card_code');
        $this->assertNotSame($old, $new);
        $this->assertNull(GiftCard::findByCode($old));
        $this->assertSame($card->id, GiftCard::findByCode($new)?->id);
        $this->assertSame('50.00', $card->fresh()->balance);
    }

    // --- Deleting ----------------------------------------------------------------------------------

    #[Test]
    public function a_card_that_was_never_spent_can_be_deleted_with_its_history(): void
    {
        $card = $this->issued('50.00');
        app(GiftCards::class)->adjust($card, $this->usd('5.00'), 'Goodwill');

        $this->actingAs($this->admin())->delete(route('acp.commerce.gift-cards.destroy', $card))
            ->assertRedirect(route('acp.commerce.gift-cards.index'))->assertSessionHas('success', 'Gift card deleted.');

        $this->assertDatabaseMissing('gift_cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('gift_card_transactions', ['gift_card_id' => $card->id]);
    }

    #[Test]
    public function a_card_that_an_order_used_can_only_be_switched_off(): void
    {
        $card = GiftCard::factory()->code(self::CODE)->worth('20.00')->withHistory()->create();
        $this->cartWith(Product::factory()->priced('50.00')->stocked(5)->create(), 1);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE]);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $this->app['auth']->forgetGuards();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('acp.commerce.gift-cards.show', $card))->assertInertia(fn (Assert $page) => $page
            ->where('deletable', false)
            ->where('card.orders', 1));

        $this->actingAs($admin)->delete(route('acp.commerce.gift-cards.destroy', $card))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('gift_cards', ['id' => $card->id]);
        $this->assertSame(1, Order::query()->where('gift_card_id', $card->id)->count());
    }

    #[Test]
    public function the_database_itself_refuses_to_lose_a_card_an_order_used(): void
    {
        $card = GiftCard::factory()->code(self::CODE)->worth('20.00')->withHistory()->create();
        Order::factory()->create(['gift_card_id' => $card->id, 'gift_card_total' => '5.00']);

        $this->expectException(QueryException::class);

        DB::transaction(fn () => $card->delete());
    }

    // --- On the order page and the overview ------------------------------------------------------

    #[Test]
    public function an_order_page_shows_the_card_that_paid_and_where_a_refund_will_go(): void
    {
        $card = GiftCard::factory()->code(self::CODE)->worth('20.00')->withHistory()->create();
        $this->cartWith(Product::factory()->priced('50.00')->stocked(5)->create(), 1);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE]);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('order.grand_total', '50.00')
            ->where('order.gift_card', ['id' => $card->id, 'last_four' => 'NPQR', 'amount' => '20.00'])
            ->where('order.amount_due', '30.00')
            ->where('refunding.gift_card_refundable', '20.00')
            ->where('refunding.gift_card_last_four', 'NPQR')
            ->where('payments.0.amount', '30.00'));
    }

    #[Test]
    public function staff_refund_through_the_order_page_and_the_card_is_paid_back_first(): void
    {
        $card = GiftCard::factory()->code(self::CODE)->worth('20.00')->withHistory()->create();
        $this->cartWith(Product::factory()->priced('50.00')->stocked(5)->create(), 1);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => self::CODE]);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        $this->app['auth']->forgetGuards();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.orders.refunds.store', $order), ['amount' => '12.00', 'token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('success', 'Put 12.00 USD back on the gift card.');

        $this->assertSame('12.00', $card->fresh()->balance);
        $this->actingAs($admin)->get(route('acp.commerce.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('refunds.0.gift_card', true)
            ->where('refunds.0.can_check', false)
            ->where('refunding.gift_card_refundable', '8.00'));
    }

    #[Test]
    public function the_commerce_overview_says_what_is_owed_on_cards(): void
    {
        GiftCard::factory()->worth('10.00')->create();
        GiftCard::factory()->worth('25.50')->create();
        GiftCard::factory()->worth('99.00')->inactive()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.gift_cards', ['active' => 2, 'outstanding' => 35.5]));
    }
}
