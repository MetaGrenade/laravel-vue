<?php

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ForeignKeyIndexes;
use Tests\TestCase;

/**
 * The gift card tables and columns on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A
 * statement that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class GiftCardMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    #[Test]
    public function the_tables_and_columns_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('gift_cards', ['code_hash', 'last_four', 'currency', 'initial_amount', 'balance', 'is_active', 'expires_at', 'note', 'issued_by']));
        $this->assertTrue(Schema::hasColumn('carts', 'gift_card_id'));
        $this->assertTrue(Schema::hasColumns('orders', ['gift_card_id', 'gift_card_total']));
        $this->assertTrue(Schema::hasColumn('refunds', 'gift_card_id'));

        ForeignKeyIndexes::takeOff();
        $this->migration('2026_10_18_000100_add_gift_cards_to_carts_orders_and_refunds')->down();
        $this->migration('2026_10_18_000000_create_gift_cards_tables')->down();

        $this->assertFalse(Schema::hasTable('gift_cards'));
        $this->assertFalse(Schema::hasTable('gift_card_transactions'));
        $this->assertFalse(Schema::hasColumn('carts', 'gift_card_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'gift_card_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'gift_card_total'));
        $this->assertFalse(Schema::hasColumn('refunds', 'gift_card_id'));

        $this->migration('2026_10_18_000000_create_gift_cards_tables')->up();
        $this->migration('2026_10_18_000100_add_gift_cards_to_carts_orders_and_refunds')->up();
        ForeignKeyIndexes::putBack();

        $this->assertTrue(Schema::hasTable('gift_cards'));
        $this->assertTrue(Schema::hasColumns('orders', ['gift_card_id', 'gift_card_total']));
    }

    #[Test]
    public function orders_that_existed_before_were_paid_with_no_gift_card(): void
    {
        $order = Order::factory()->create()->refresh();

        $this->assertNull($order->gift_card_id);
        $this->assertSame('0.00', $order->gift_card_total);
        $this->assertSame($order->grand_total, $order->amountDue()->toDecimal());
    }

    #[Test]
    public function two_cards_cannot_share_a_code(): void
    {
        $card = GiftCard::factory()->create();

        try {
            DB::transaction(fn () => GiftCard::factory()->create(['code_hash' => $card->code_hash]));
            $this->fail('a second card with the same code was accepted');
        } catch (QueryException) {
            $this->assertSame(1, GiftCard::query()->count());
        }
    }

    #[Test]
    public function the_history_goes_with_its_card_but_a_card_an_order_or_refund_used_cannot_be_deleted(): void
    {
        $unused = GiftCard::factory()->withHistory()->create();
        $unused->delete();
        $this->assertSame(0, GiftCardTransaction::query()->count(), 'the history goes with the card');

        $used = GiftCard::factory()->withHistory()->create();
        $order = Order::factory()->create(['gift_card_id' => $used->id, 'gift_card_total' => '5.00']);

        try {
            DB::transaction(fn () => $used->delete());
            $this->fail('a card an order used was deleted');
        } catch (QueryException) {
            $this->assertDatabaseHas('gift_cards', ['id' => $used->id]);
        }

        $order->delete();

        $refunded = GiftCard::factory()->withHistory()->create();
        Refund::query()->create([
            'order_id' => Order::factory()->create()->id,
            'gift_card_id' => $refunded->id,
            'idempotency_key' => 'refund-test',
            'status' => 'succeeded',
            'amount' => '5.00',
            'currency' => 'USD',
        ]);

        try {
            DB::transaction(fn () => $refunded->delete());
            $this->fail('a card a refund went to was deleted');
        } catch (QueryException) {
            $this->assertDatabaseHas('gift_cards', ['id' => $refunded->id]);
        }
    }

    #[Test]
    public function a_cart_that_loses_its_card_simply_has_none(): void
    {
        $card = GiftCard::factory()->create();
        $cart = Cart::query()->create(['session_id' => str_repeat('a', 40), 'status' => 'open', 'currency' => 'USD', 'gift_card_id' => $card->id]);

        $card->delete();

        $this->assertNull($cart->fresh()->gift_card_id);
    }
}
