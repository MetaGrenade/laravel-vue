<?php

namespace Tests\Feature\Commerce;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Models\User;
use App\Support\Commerce\GiftCards\GiftCardCode;
use App\Support\Commerce\GiftCards\GiftCardException;
use App\Support\Commerce\GiftCards\GiftCards;
use App\Support\Commerce\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift cards on their own: how a code is made and found, and the one place a balance changes.
 */
class GiftCardServiceTest extends TestCase
{
    use RefreshDatabase;

    private GiftCards $cards;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.currency' => 'USD']);
        $this->cards = app(GiftCards::class);
    }

    private function usd(string $amount): Money
    {
        return Money::parse($amount, 'USD');
    }

    private function orderOn(GiftCard $card, string $total = '30.00'): Order
    {
        return Order::factory()->create(['gift_card_id' => $card->id, 'gift_card_total' => $total]);
    }

    // --- Codes ------------------------------------------------------------------------------------

    #[Test]
    public function a_code_is_sixteen_characters_in_four_groups_without_the_ones_that_look_alike(): void
    {
        foreach (range(1, 200) as $unused) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}$/', GiftCardCode::generate());
        }
    }

    #[Test]
    public function codes_do_not_repeat(): void
    {
        $codes = array_map(fn () => GiftCardCode::generate(), range(1, 500));

        $this->assertSame(500, count(array_unique($codes)));
    }

    #[Test]
    public function a_code_is_matched_whatever_the_case_spacing_or_dashes(): void
    {
        $card = GiftCard::factory()->code('ABCD-EFGH-JKLM-NPQR')->create();

        foreach (['ABCD-EFGH-JKLM-NPQR', 'abcd-efgh-jklm-npqr', 'abcdefghjklmnpqr', '  ABCD EFGH JKLM NPQR  ', 'abcd_efgh.jklm/npqr'] as $typed) {
            $this->assertSame($card->id, GiftCard::findByCode($typed)?->id, "'{$typed}'");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notCodes(): array
    {
        return [
            'nothing' => [''],
            'too short' => ['ABCD-EFGH-JKLM'],
            'too long' => ['ABCD-EFGH-JKLM-NPQR-STUV'],
            'a letter that is never used (O)' => ['ABCD-EFGH-JKLM-NPQO'],
            'a digit that is never used (0)' => ['ABCD-EFGH-JKLM-NPQ0'],
            'a digit that is never used (1)' => ['ABCD-EFGH-JKLM-NPQ1'],
            'a letter that is never used (I)' => ['ABCD-EFGH-JKLM-NPQI'],
        ];
    }

    #[Test]
    #[DataProvider('notCodes')]
    public function text_that_cannot_be_a_code_matches_nothing(string $typed): void
    {
        GiftCard::factory()->code('ABCD-EFGH-JKLM-NPQR')->create();

        $this->assertNull(GiftCard::findByCode($typed));
    }

    #[Test]
    public function a_code_that_is_well_formed_but_unknown_matches_nothing(): void
    {
        GiftCard::factory()->code('ABCD-EFGH-JKLM-NPQR')->create();

        $this->assertNull(GiftCard::findByCode('ZZZZ-ZZZZ-ZZZZ-ZZZZ'));
    }

    // --- Issuing ----------------------------------------------------------------------------------

    #[Test]
    public function issuing_a_card_gives_the_code_once_and_keeps_only_what_is_needed_to_recognise_it(): void
    {
        $staff = User::factory()->create();

        $card = $this->cards->issue($this->usd('25.00'), $staff, now()->addYear(), 'For Ada, a thank-you');

        $this->assertMatchesRegularExpression('/^[A-Z2-9-]{19}$/', (string) $card->plainCode);
        $this->assertSame('25.00', $card->fresh()->balance);
        $this->assertSame('25.00', $card->fresh()->initial_amount);
        $this->assertSame('USD', $card->currency);
        $this->assertSame($staff->id, $card->issued_by);
        $this->assertSame('For Ada, a thank-you', $card->note);

        // Found by the code it was given...
        $this->assertSame($card->id, GiftCard::findByCode((string) $card->plainCode)?->id);

        // ...but the code is nowhere in the database: not on the card, not in its history.
        $stored = json_encode([DB::table('gift_cards')->get(), DB::table('gift_card_transactions')->get()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $card->plainCode, $stored);
        $this->assertStringNotContainsString(GiftCardCode::normalise((string) $card->plainCode), $stored);
        $this->assertSame(GiftCardCode::lastFour(GiftCardCode::normalise((string) $card->plainCode)), $card->last_four);

        // And it never leaves with the model.
        $this->assertArrayNotHasKey('code_hash', $card->fresh()->toArray());
        $this->assertNull(GiftCard::query()->find($card->id)->plainCode);
    }

    #[Test]
    public function a_new_card_starts_its_history_with_what_it_was_issued_for(): void
    {
        $staff = User::factory()->create();
        $card = $this->cards->issue($this->usd('25.00'), $staff);

        $entry = $card->transactions()->sole();

        $this->assertSame(GiftCardTransaction::ISSUED, $entry->type);
        $this->assertSame('25.00', $entry->amount);
        $this->assertSame('25.00', $entry->balance_after);
        $this->assertSame($staff->id, $entry->user_id);
        $this->assertNull($entry->order_id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badAmounts(): array
    {
        return [
            'zero' => ['0.00', 'worth something'],
            'less than nothing' => ['-5.00', 'worth something'],
            'more than a card can hold' => ['100000000.00', 'more than a gift card can hold'],
        ];
    }

    #[Test]
    #[DataProvider('badAmounts')]
    public function a_card_has_to_be_worth_something_it_can_hold(string $amount, string $why): void
    {
        $this->expectException(GiftCardException::class);
        $this->expectExceptionMessage($why);

        try {
            $this->cards->issue($this->usd($amount));
        } finally {
            $this->assertSame(0, GiftCard::query()->count());
        }
    }

    #[Test]
    public function a_card_is_in_the_shops_currency(): void
    {
        $this->expectException(GiftCardException::class);
        $this->expectExceptionMessage('shop\'s currency, USD');

        $this->cards->issue(Money::parse('10.00', 'EUR'));
    }

    // --- The balance and its history ---------------------------------------------------------------

    #[Test]
    public function every_change_to_the_balance_is_in_the_history_and_the_two_always_agree(): void
    {
        $staff = User::factory()->create();
        $card = $this->cards->issue($this->usd('100.00'), $staff);
        $order = $this->orderOn($card, '30.00');

        $this->cards->redeem($card, $this->usd('30.00'), $order);                  // 70
        $this->cards->release($order, 'cancelled');                                // 100
        $this->cards->redeem($card, $this->usd('40.00'), $order);                  // 60
        $this->cards->credit($order, $this->usd('10.00'), $staff);                 // 70
        $this->cards->adjust($card, $this->usd('5.00'), 'A goodwill gesture', $staff);   // 75
        $this->cards->adjust($card, $this->usd('-20.00'), 'Taken in error', $staff);     // 55

        $card->refresh();

        $this->assertSame('55.00', $card->balance);
        $this->assertSame('55.00', $this->cards->ledgerBalance($card)->toDecimal());
        $this->assertSame(
            ['issued', 'redeemed', 'released', 'redeemed', 'refunded', 'adjusted', 'adjusted'],
            $card->transactions()->pluck('type')->all(),
        );
        $this->assertSame(
            ['100.00', '70.00', '100.00', '60.00', '70.00', '75.00', '55.00'],
            $card->transactions()->pluck('balance_after')->all(),
            'each entry says what the balance was afterwards',
        );
    }

    #[Test]
    public function a_card_cannot_be_overdrawn(): void
    {
        $card = $this->cards->issue($this->usd('20.00'));
        $order = $this->orderOn($card, '25.00');

        try {
            $this->cards->redeem($card, $this->usd('25.00'), $order);
            $this->fail('spending more than the card held was allowed');
        } catch (GiftCardException) {
            $this->assertSame('20.00', $card->fresh()->balance);
            $this->assertSame(1, $card->transactions()->count(), 'and nothing was written');
        }
    }

    #[Test]
    public function releasing_an_order_gives_its_share_back_once(): void
    {
        $card = $this->cards->issue($this->usd('50.00'));
        $order = $this->orderOn($card, '30.00');
        $this->cards->redeem($card, $this->usd('30.00'), $order);

        $this->assertSame('30.00', $this->cards->held($order)->toDecimal());
        $this->assertSame('30.00', $this->cards->release($order, 'expired')->toDecimal());
        $this->assertSame('50.00', $card->fresh()->balance);

        $entries = $card->transactions()->count();
        $this->assertTrue($this->cards->release($order, 'expired')->isZero(), 'a second release finds nothing held');
        $this->assertSame('50.00', $card->fresh()->balance);
        $this->assertSame($entries, $card->transactions()->count(), 'and writes nothing');
        $this->assertTrue($this->cards->held($order)->isZero());
    }

    #[Test]
    public function giving_one_orders_share_back_leaves_another_orders_share_where_it_is(): void
    {
        $card = $this->cards->issue($this->usd('100.00'));
        $one = $this->orderOn($card, '30.00');
        $two = $this->orderOn($card, '25.00');
        $this->cards->redeem($card, $this->usd('30.00'), $one);
        $this->cards->redeem($card, $this->usd('25.00'), $two);

        $this->assertSame('30.00', $this->cards->release($one, 'expired')->toDecimal());

        $this->assertTrue($this->cards->held($one)->isZero());
        $this->assertSame('25.00', $this->cards->held($two)->toDecimal());
        $this->assertSame('75.00', $card->fresh()->balance);
    }

    #[Test]
    public function an_order_without_a_card_holds_nothing_and_releases_nothing(): void
    {
        $order = Order::factory()->create();

        $this->assertTrue($this->cards->held($order)->isZero());
        $this->assertTrue($this->cards->release($order, 'cancelled')->isZero());
        $this->assertTrue($this->cards->reapply($order)->isZero());
    }

    #[Test]
    public function an_order_paid_after_it_was_cancelled_takes_its_share_again(): void
    {
        $card = $this->cards->issue($this->usd('50.00'));
        $order = $this->orderOn($card, '30.00');
        $this->cards->redeem($card, $this->usd('30.00'), $order);
        $this->cards->release($order, 'expired');

        $short = $this->cards->reapply($order);

        $this->assertTrue($short->isZero());
        $this->assertSame('20.00', $card->fresh()->balance);
        $this->assertSame('30.00', $this->cards->held($order)->toDecimal());

        $this->assertTrue($this->cards->reapply($order)->isZero(), 'taking it again when it is already taken does nothing');
        $this->assertSame('20.00', $card->fresh()->balance);
    }

    #[Test]
    public function when_the_card_cannot_cover_it_again_the_shortfall_is_reported_and_the_rest_is_taken(): void
    {
        $card = $this->cards->issue($this->usd('50.00'));
        $order = $this->orderOn($card, '30.00');
        $this->cards->redeem($card, $this->usd('30.00'), $order);
        $this->cards->release($order, 'expired');

        // Someone else spent most of the card in the meantime, and it was switched off and has expired too.
        $this->cards->adjust($card, $this->usd('-35.00'), 'Spent elsewhere');
        $card->forceFill(['is_active' => false, 'expires_at' => now()->subDay()])->save();

        $short = $this->cards->reapply($order);

        $this->assertSame('15.00', $short->toDecimal());
        $this->assertSame('0.00', $card->fresh()->balance);
        $this->assertSame('15.00', $this->cards->held($order)->toDecimal());
    }

    #[Test]
    public function a_refund_goes_back_onto_a_card_that_has_since_been_disabled_or_has_expired(): void
    {
        $card = $this->cards->issue($this->usd('50.00'));
        $order = $this->orderOn($card, '30.00');
        $this->cards->redeem($card, $this->usd('30.00'), $order);
        $card->forceFill(['is_active' => false, 'expires_at' => now()->subDay()])->save();

        $this->cards->credit($order, $this->usd('12.00'));

        $this->assertSame('32.00', $card->fresh()->balance);
    }

    #[Test]
    public function the_team_can_add_or_take_away_value_but_only_with_a_reason_and_not_below_nothing(): void
    {
        $card = $this->cards->issue($this->usd('10.00'));

        $this->cards->adjust($card, $this->usd('5.00'), 'Compensation for a late parcel');
        $this->assertSame('15.00', $card->fresh()->balance);

        foreach ([
            [$this->usd('0.00'), 'Nothing', 'Enter an amount'],
            [$this->usd('3.00'), '   ', 'Say why'],
            [$this->usd('-15.01'), 'Too much', 'more than the gift card holds'],
            [$this->usd('99999999.99'), 'Too big', 'more than a gift card can hold'],
        ] as [$change, $note, $message]) {
            try {
                $this->cards->adjust($card, $change, $note);
                $this->fail("'{$message}' was allowed");
            } catch (GiftCardException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame('15.00', $card->fresh()->balance);
        $this->assertSame(2, $card->transactions()->count(), 'refused changes leave no trace');
    }

    #[Test]
    public function replacing_the_code_keeps_the_balance_and_stops_the_old_code_working(): void
    {
        $staff = User::factory()->create();
        $card = $this->cards->issue($this->usd('40.00'), $staff);
        $old = (string) $card->plainCode;

        $new = $this->cards->regenerate($card, $staff);

        $this->assertNotSame($old, $new->plainCode);
        $this->assertNull(GiftCard::findByCode($old), 'the old code is dead');
        $this->assertSame($card->id, GiftCard::findByCode((string) $new->plainCode)?->id);
        $this->assertSame('40.00', $new->fresh()->balance);

        $entry = $card->transactions()->get()->last();
        $this->assertSame(GiftCardTransaction::ADJUSTED, $entry->type);
        $this->assertSame('0.00', $entry->amount);
        $this->assertSame('Code replaced', $entry->note);
        $this->assertSame($staff->id, $entry->user_id);
        $this->assertSame('40.00', $this->cards->ledgerBalance($card)->toDecimal());
    }

    // --- What a card says about itself --------------------------------------------------------------

    #[Test]
    public function a_card_says_whether_it_can_be_spent_and_why_not(): void
    {
        $usable = GiftCard::factory()->create();

        $this->assertNull($usable->problem('USD'));
        $this->assertSame(GiftCard::ACTIVE, $usable->status());

        $this->assertStringContainsString('cannot be used', (string) GiftCard::factory()->inactive()->create()->problem('USD'));
        $this->assertStringContainsString('expired', (string) GiftCard::factory()->expired()->create()->problem('USD'));
        $this->assertStringContainsString('no balance', (string) GiftCard::factory()->spent()->create()->problem('USD'));
        $this->assertStringContainsString('in USD', (string) $usable->problem('EUR'));

        $this->assertSame(GiftCard::DISABLED, GiftCard::factory()->inactive()->create()->status());
        $this->assertSame(GiftCard::EXPIRED, GiftCard::factory()->expired()->create()->status());
        $this->assertSame(GiftCard::USED_UP, GiftCard::factory()->spent()->create()->status());
        $this->assertSame(GiftCard::ACTIVE, GiftCard::factory()->expiring()->create()->status(), 'a card with a date still ahead is fine');
    }

    #[Test]
    public function the_card_is_shown_to_the_team_by_its_last_four_characters_only(): void
    {
        $card = GiftCard::factory()->code('ABCD-EFGH-JKLM-NPQR')->create();

        $this->assertSame('••••-••••-••••-NPQR', $card->masked());
    }
}
