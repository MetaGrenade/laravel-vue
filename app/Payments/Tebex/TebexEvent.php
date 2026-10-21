<?php

namespace App\Payments\Tebex;

use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\Money;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * One delivery from Tebex's webhooks: `{id, type, date, subject}`, read defensively, since the shape of a subject is
 * what a fixture cannot prove (see docs/tebex.md, "Checked against a real store").
 */
final class TebexEvent
{
    public const VALIDATION = 'validation.webhook';

    public const PAYMENT_COMPLETED = 'payment.completed';

    public const PAYMENT_DECLINED = 'payment.declined';

    public const PAYMENT_REFUNDED = 'payment.refunded';

    public const DISPUTE_OPENED = 'payment.dispute.opened';

    public const DISPUTE_WON = 'payment.dispute.won';

    public const DISPUTE_LOST = 'payment.dispute.lost';

    public const DISPUTE_CLOSED = 'payment.dispute.closed';

    /** The payment statuses Tebex describes (the `status.id` of a payment). */
    public const STATUS_COMPLETE = 1;

    public const STATUS_REFUND = 2;

    public const STATUS_CHARGEBACK = 3;

    public const STATUS_DECLINED = 18;

    public const STATUS_REFUND_PENDING = 21;

    /**
     * What is left out of a delivery before it is kept: the buyer's details, which nothing in the shop needs. (The
     * game account stays, because the order page shows who a package was delivered to.)
     */
    private const PERSONAL = ['email', 'first_name', 'last_name', 'ip', 'postal_code', 'marketing_consent', 'card_number', 'phone', 'address'];

    /**
     * @param  array<string, mixed>  $subject
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $date,
        public readonly array $subject,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The body, decoded.
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        $subject = $data['subject'] ?? [];
        $date = $data['date'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '' || ! is_array($subject)) {
            return null;
        }

        return new self($id, $type, is_string($date) ? $date : null, $subject);
    }

    public function isPayment(): bool
    {
        return str_starts_with($this->type, 'payment.');
    }

    public function isDispute(): bool
    {
        return str_starts_with($this->type, 'payment.dispute.');
    }

    public function isRecurring(): bool
    {
        return str_starts_with($this->type, 'recurring-payment.');
    }

    /**
     * The id Tebex gives the payment (`tbx-...`). A dispute's subject is not described, so it is looked for where a
     * payment would be if it is wrapped.
     */
    public function transactionId(): ?string
    {
        foreach (['transaction_id', 'payment.transaction_id', 'transaction.transaction_id'] as $key) {
            $value = Arr::get($this->subject, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The id Tebex gives a recurring payment (`tbx-r-...`), as the subject of a `recurring-payment.*` delivery.
     */
    public function reference(): ?string
    {
        $reference = Arr::get($this->subject, 'reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * The recurring payment a payment belongs to: its first payment and each renewal all name it. Null for a one-off.
     */
    public function recurringReference(): ?string
    {
        $reference = Arr::get($this->subject, 'recurring_payment_reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * When Tebex says the delivery was made (the envelope's `date`). Null when it is missing or not a date.
     */
    public function at(): ?Carbon
    {
        return $this->parseTime($this->date);
    }

    /**
     * A moment in the subject (`created_at`, `next_payment_at`, `cancelled_at`...). Null when it is missing or not a date.
     */
    public function moment(string $key): ?Carbon
    {
        $value = Arr::get($this->subject, $key);

        return $this->parseTime(is_string($value) ? $value : null);
    }

    /**
     * Tebex's own words for the status (`Active`, `Cancelled`...).
     */
    public function statusDescription(): ?string
    {
        $description = Arr::get($this->subject, 'status.description');

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * What a recurring payment costs each time (the subject's `price`). Null when it is not a usable amount.
     */
    public function price(): ?Money
    {
        return $this->money(Arr::get($this->subject, 'price'));
    }

    public function failCount(): int
    {
        $count = Arr::get($this->subject, 'fail_count');

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function cancelReason(): ?string
    {
        $reason = Arr::get($this->subject, 'cancel_reason');

        return is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 250) : null;
    }

    /**
     * A payment nested in a recurring payment (`initial_payment`, `last_payment`), as a payment delivery of its own, so
     * it can be read and matched the way any payment is. Null when there is none.
     */
    public function nestedPayment(string $key): ?self
    {
        $payment = Arr::get($this->subject, $key);

        return is_array($payment) && $payment !== [] ? new self("{$this->id}:{$key}", self::PAYMENT_COMPLETED, $this->date, $payment) : null;
    }

    public function statusId(): ?int
    {
        $id = Arr::get($this->subject, 'status.id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * What the buyer paid Tebex: `price_paid`, or `price` when it is not given. Null when neither is a usable amount.
     */
    public function paid(): ?Money
    {
        foreach (['price_paid', 'price'] as $key) {
            $money = $this->money(Arr::get($this->subject, $key));

            if ($money !== null) {
                return $money;
            }
        }

        return null;
    }

    /**
     * The packages the payment was for, as Tebex's package id => how many in all (a package listed twice adds up).
     * Null when the payment lists none, or any entry that is not a whole id with a whole quantity that is not negative:
     * what cannot be read cannot be said to match anything, and a negative one could cancel out another entry. (A
     * quantity of none is not refused here: it lists a package the order does not hold, or adds nothing to one it does,
     * and the comparison with the order deals with both.)
     *
     * @return array<int, int>|null
     */
    public function purchased(): ?array
    {
        $products = Arr::get($this->subject, 'products');

        if (! is_array($products) || $products === []) {
            return null;
        }

        $bought = [];

        foreach ($products as $product) {
            $id = is_array($product) ? ($product['id'] ?? null) : null;
            $quantity = is_array($product) ? ($product['quantity'] ?? null) : null;

            if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity))) || (int) $quantity < 0) {
                return null;
            }

            $bought[(int) $id] = ($bought[(int) $id] ?? 0) + (int) $quantity;
        }

        ksort($bought);

        return $bought;
    }

    /**
     * The order and nonce the basket was made with (see {@see TebexProvider::startCheckout()}).
     *
     * Tebex says only that a basket's custom data is "included with webhook responses". The one place its documented
     * payment carries a `custom` is on each product, so that is looked at as well as the top of the subject; whichever
     * holds ours is used.
     *
     * @return array{order: string, nonce: string}|null
     */
    public function basketCustom(): ?array
    {
        $candidates = [Arr::get($this->subject, 'custom')];

        foreach ((array) Arr::get($this->subject, 'products', []) as $product) {
            $candidates[] = is_array($product) ? ($product['custom'] ?? null) : null;
        }

        foreach ($candidates as $custom) {
            if (is_array($custom) && is_string($custom['order'] ?? null) && is_string($custom['nonce'] ?? null)) {
                return ['order' => $custom['order'], 'nonce' => $custom['nonce']];
            }
        }

        return null;
    }

    /**
     * The game account the packages were bought for, in the shape kept on a payment.
     *
     * @return array{username: string, username_id: string}|null
     */
    public function buyer(): ?array
    {
        $account = Arr::get($this->subject, 'customer.username');

        if (! is_array($account) || ! is_string($account['username'] ?? null) || $account['username'] === '' || ! is_scalar($account['id'] ?? null)) {
            return null;
        }

        return ['username' => $account['username'], 'username_id' => (string) $account['id']];
    }

    public function declineMessage(): ?string
    {
        $reason = Arr::get($this->subject, 'decline_reason');

        if (! is_array($reason)) {
            return null;
        }

        $text = $reason['message'] ?? $reason['code'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * What is kept of the delivery: all of it but the buyer's personal details, at any depth.
     *
     * @return array{id: string, type: string, date: string|null, subject: array<string, mixed>}
     */
    public function redacted(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'date' => $this->date,
            'subject' => $this->withoutPersonalDetails($this->subject),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function withoutPersonalDetails(array $data): array
    {
        $kept = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::PERSONAL, true)) {
                continue;
            }

            $kept[$key] = is_array($value) ? $this->withoutPersonalDetails($value) : $value;
        }

        return $kept;
    }

    private function money(mixed $price): ?Money
    {
        if (! is_array($price) || ! is_numeric($price['amount'] ?? null) || ! is_string($price['currency'] ?? null)) {
            return null;
        }

        try {
            $money = Money::parse($price['amount'], strtoupper($price['currency']));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $money->isNegative() ? null : $money;
    }

    private function parseTime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
