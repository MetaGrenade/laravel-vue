<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One licence key. While `order_item_id` is empty it is in the pool of the stock it is sold from; once
 * an order line has it, it is that customer's. The key itself is encrypted and never serialised by
 * accident: read `$key->value` only where it is meant to be shown.
 */
class LicenceKey extends Model
{
    use HasFactory;

    /** Why a key was withdrawn from its customer. */
    public const BY_REFUND = 'refunded';

    public const BY_STAFF = 'staff';

    protected $fillable = [
        'inventory_item_id',
        'value',
        'fingerprint',
        'order_item_id',
        'issued_at',
        'revoked_at',
        'revoked_reason',
        'added_by',
    ];

    /** Keeps the secret out of `toArray()` and JSON, and so out of Inertia props. */
    protected $hidden = ['value', 'fingerprint'];

    protected $casts = [
        'value' => 'encrypted',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * A keyed hash of a key, so a duplicate is found without decrypting the pool. Keys are compared as
     * typed, apart from the whitespace around them.
     */
    public static function fingerprintOf(string $value): string
    {
        return hash_hmac('sha256', trim($value), (string) config('app.key'));
    }

    /**
     * Keys still in the pool.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('order_item_id');
    }

    /**
     * Keys a customer holds and may still see: given to a line, and not withdrawn.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('order_item_id')->whereNull('revoked_at');
    }

    /**
     * Keys that count as given to a line: everything it was handed except what staff withdrew. A key
     * taken back because of a refund still counts, so a refunded order is never given new keys.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCountedAsIssued(Builder $query): Builder
    {
        return $query->whereNotNull('order_item_id')->where(function ($inner) {
            $inner->whereNull('revoked_reason')->orWhere('revoked_reason', self::BY_REFUND);
        });
    }

    /**
     * Enough of the key to recognise it, never all of it: for lists staff look at.
     */
    public function masked(): string
    {
        $value = (string) $this->value;
        $length = mb_strlen($value);

        if ($length < 9) {
            return str_repeat('•', max($length, 4));
        }

        return str_repeat('•', min($length - 4, 12)).mb_substr($value, -4);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
