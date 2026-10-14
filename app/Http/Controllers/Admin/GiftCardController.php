<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Commerce\GiftCards\GiftCardCode;
use App\Support\Commerce\GiftCards\GiftCardException;
use App\Support\Commerce\GiftCards\GiftCards;
use App\Support\Commerce\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gift cards in the ACP: issuing them, looking at what they hold and what happened to it, and the few things staff
 * can do to one. The code of a card is not kept, so it is shown once, when the card is made or its code is replaced,
 * and never again.
 *
 * Making a card, adding value and replacing a code create value, so they need the create permission. Switching a card
 * off or changing its note and date is an edit, and a card that has never been spent can be deleted.
 */
class GiftCardController extends Controller
{
    use InteractsWithInertiaPagination;

    /** What the list can be narrowed to, besides everything. */
    private const STATUSES = [GiftCard::ACTIVE, GiftCard::USED_UP, GiftCard::EXPIRED, GiftCard::DISABLED];

    public function __construct(private readonly GiftCards $cards) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $status = in_array($status, self::STATUSES, true) ? $status : 'all';

        $paginator = GiftCard::query()
            ->with('issuer:id,nickname')
            ->when($status !== 'all', fn ($query) => $this->ofStatus($query, $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $code = GiftCardCode::normalise($search);

                $query->whereLike('note', "%{$search}%")->orWhere('last_four', $code);

                // A whole code finds its card, without the code being kept anywhere.
                if (GiftCardCode::isWellFormed($code)) {
                    $query->orWhere('code_hash', GiftCardCode::hash($code));
                }
            }))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $counts = ['all' => GiftCard::query()->count()];

        foreach (self::STATUSES as $each) {
            $counts[$each] = $this->ofStatus(GiftCard::query(), $each)->count();
        }

        $currency = strtoupper((string) config('commerce.currency', 'USD'));

        return Inertia::render('acp/CommerceGiftCards', [
            'cards' => [
                'data' => $paginator->getCollection()->map(fn (GiftCard $card) => $this->row($card))->values()->all(),
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['search' => $search, 'status' => $status],
            'counts' => $counts,
            // What the shop owes in unspent cards: the balance of those that can still be spent.
            'outstanding' => Money::parse((string) $this->ofStatus(GiftCard::query(), GiftCard::ACTIVE)->sum('balance'), $currency)->toDecimal(),
            'currency' => $currency,
            'enabled' => $this->cards->enabled(),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('acp/CommerceGiftCardCreate', [
            'currency' => strtoupper((string) config('commerce.currency', 'USD')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Enter what the card is worth.',
            'amount.gt' => 'A gift card has to be worth something.',
            'amount.decimal' => 'Use at most two decimals.',
            'expires_at.after' => 'Pick a day after today, or leave it empty for a card that never expires.',
        ]);

        $currency = strtoupper((string) config('commerce.currency', 'USD'));

        try {
            $card = $this->cards->issue(
                Money::parse((string) $validated['amount'], $currency),
                $request->user(),
                filled($validated['expires_at'] ?? null) ? Carbon::parse($validated['expires_at'])->endOfDay() : null,
                $validated['note'] ?? null,
            );
        } catch (GiftCardException $exception) {
            return back()->withInput()->withErrors(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('acp.commerce.gift-cards.show', $card)
            ->with('gift_card_code', $card->plainCode)
            ->with('success', 'Gift card made. Copy its code now: it cannot be shown again.');
    }

    public function show(Request $request, GiftCard $giftCard): Response
    {
        $user = $request->user();
        $giftCard->load('issuer:id,nickname');

        $ledger = GiftCardTransaction::query()
            ->where('gift_card_id', $giftCard->id)
            ->with(['order:id,public_id,number', 'user:id,nickname'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Only for the request that made (or replaced) the code: a flash message is gone by the next one.
        $code = $request->session()->get('gift_card_code');

        return Inertia::render('acp/CommerceGiftCardShow', [
            'card' => $this->row($giftCard) + [
                'initial_amount' => $giftCard->initial_amount,
                'is_active' => $giftCard->is_active,
                'expires_at_date' => $giftCard->expires_at?->toDateString(),
                'ledger_matches' => $this->cards->ledgerBalance($giftCard)->equals($giftCard->balanceMoney()),
                'orders' => Order::query()->where('gift_card_id', $giftCard->id)->count(),
            ],
            'code' => is_string($code) ? $code : null,
            'ledger' => [
                'data' => $ledger->getCollection()->map(fn (GiftCardTransaction $entry) => [
                    'id' => $entry->id,
                    'type' => $entry->type,
                    'amount' => $entry->amount,
                    'balance_after' => $entry->balance_after,
                    'note' => $entry->note,
                    'by' => $entry->user?->nickname,
                    'order' => $entry->order ? ['number' => $entry->order->number, 'public_id' => $entry->order->public_id] : null,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])->values()->all(),
                ...$this->inertiaPagination($ledger),
            ],
            'currency' => $giftCard->currency,
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
            'deletable' => $this->deletable($giftCard),
        ]);
    }

    /**
     * Switch a card on or off, and change its expiry date and note. Not its value: that is {@see self::adjust()}.
     */
    public function update(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $giftCard->forceFill([
            'is_active' => (bool) $validated['is_active'],
            'expires_at' => filled($validated['expires_at'] ?? null) ? Carbon::parse($validated['expires_at'])->endOfDay() : null,
            'note' => filled($validated['note'] ?? null) ? $validated['note'] : null,
        ])->save();

        return back()->with('success', 'Gift card updated.');
    }

    /**
     * Add value to the card, or take some away, with a reason that stays in its history.
     */
    public function adjust(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'amount.gt' => 'Enter an amount greater than zero.',
            'amount.decimal' => 'Use at most two decimals.',
            'note.required' => 'Say why, so the history of the card explains it.',
        ]);

        $change = Money::parse((string) $validated['amount'], $giftCard->currency);

        if ($validated['direction'] === 'remove') {
            $change = Money::ofMinor(-$change->minor, $change->currency);
        }

        try {
            $this->cards->adjust($giftCard, $change, $validated['note'], $request->user());
        } catch (GiftCardException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }

        return back()->with('success', $validated['direction'] === 'add' ? 'Value added.' : 'Value taken away.');
    }

    /**
     * Give the card a new code (the old one stops working), for a customer who lost it.
     */
    public function regenerate(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $card = $this->cards->regenerate($giftCard, $request->user());

        return back()
            ->with('gift_card_code', $card->plainCode)
            ->with('success', 'New code made. Copy it now: it cannot be shown again.');
    }

    /**
     * Delete a card that has never been spent. One that an order has used is only ever switched off, so the
     * order's history keeps its card.
     */
    public function destroy(GiftCard $giftCard): RedirectResponse
    {
        if (! $this->deletable($giftCard)) {
            return back()->with('error', 'This card has been used on an order, so it can only be switched off.');
        }

        $giftCard->delete();

        return redirect()->route('acp.commerce.gift-cards.index')->with('success', 'Gift card deleted.');
    }

    private function deletable(GiftCard $card): bool
    {
        return ! Order::query()->where('gift_card_id', $card->id)->exists()
            && ! Refund::query()->where('gift_card_id', $card->id)->exists()
            && ! GiftCardTransaction::query()->where('gift_card_id', $card->id)->whereNotNull('order_id')->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GiftCard $card): array
    {
        return [
            'id' => $card->id,
            'masked' => $card->masked(),
            'last_four' => $card->last_four,
            'currency' => $card->currency,
            'balance' => $card->balance,
            'initial_amount' => $card->initial_amount,
            'status' => $card->status(),
            'expires_at' => $card->expires_at?->toIso8601String(),
            'note' => $card->note,
            'issued_by' => $card->issuer?->nickname,
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }

    /**
     * Narrow a query to cards in one state, the same states {@see GiftCard::status()} names.
     *
     * @param  Builder<GiftCard>  $query
     * @return Builder<GiftCard>
     */
    private function ofStatus(Builder $query, string $status): Builder
    {
        $now = now();

        return match ($status) {
            GiftCard::DISABLED => $query->where('is_active', false),
            GiftCard::EXPIRED => $query->where('is_active', true)->whereNotNull('expires_at')->where('expires_at', '<=', $now),
            GiftCard::USED_UP => $query->where('is_active', true)
                ->where(fn ($inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->where('balance', '<=', 0),
            default => $query->where('is_active', true)
                ->where(fn ($inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->where('balance', '>', 0),
        };
    }
}
