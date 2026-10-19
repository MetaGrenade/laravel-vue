<?php

namespace App\Payments\Tebex;

use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\Money;
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
}
