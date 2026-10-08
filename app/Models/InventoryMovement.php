<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const RESERVATION = 'reservation';

    public const RELEASE = 'release';

    public const RESTOCK = 'restock';

    /** Stock counted or corrected by a person, not moved by an order. */
    public const ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'inventory_item_id',
        'order_id',
        'order_item_id',
        'user_id',
        'delta',
        'reason',
        'note',
    ];

    protected $casts = [
        'delta' => 'integer',
    ];

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
