<?php

namespace App\Models;

use App\Support\Commerce\GiftCards\GiftCardCode;
use App\Support\Commerce\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored value a customer spends at checkout. The code is not kept (see the migration): it is on
 * {@see self::$plainCode} only on the instance that has just issued the card, and is never serialised.
 */
class GiftCard extends Model
{
    use HasFactory;

    /** What a card is, for the team: usable now, spent, past its date, or switched off. */
    public const ACTIVE = 'active';

    public const USED_UP = 'used_up';

    public const EXPIRED = 'expired';

    public const DISABLED = 'disabled';

    protected $fillable = [
        'code_hash',
        'last_four',
        'currency',
        'initial_amount',
        'balance',
        'is_active',
        'expires_at',
        'note',
        'issued_by',
    ];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'initial_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * The code, in the form it is shown, on the instance that issued (or replaced) it. Null everywhere
     * else, since it cannot be worked out from what is stored.
     */
    public ?string $plainCode = null;

    /**
     * The card a code belongs to, whatever case or punctuation it was typed in.
     */
    public static function findByCode(string $input): ?self
    {
        $code = GiftCardCode::normalise($input);

        if (! GiftCardCode::isWellFormed($code)) {
            return null;
        }

        return static::query()->where('code_hash', GiftCardCode::hash($code))->first();
    }

    /**
     * @return HasMany<GiftCardTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function balanceMoney(): Money
    {
        return Money::parse($this->balance, $this->currency);
    }

    /**
     * The card as the team sees it in a list: only the end of the code.
     */
    public function masked(): string
    {
        return '••••-••••-••••-'.$this->last_four;
    }

    /**
     * Why this card cannot be spent on an order in this currency right now, or null when it can.
     *
     * @param  int  $alsoAvailableMinor  Value counted as being on the card too: what the shopper's own earlier,
     *                                   unpaid checkout is holding, which starting again gives back.
     */
    public function problem(string $currency, int $alsoAvailableMinor = 0): ?string
    {
        if (! $this->is_active) {
            return 'That gift card cannot be used.';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'That gift card has expired.';
        }

        if (strtoupper($this->currency) !== strtoupper($currency)) {
            return "That gift card is in {$this->currency}, and this shop sells in {$currency}.";
        }

        if ($this->balanceMoney()->minor + $alsoAvailableMinor <= 0) {
            return 'That gift card has no balance left.';
        }

        return null;
    }

    /**
     * One word for the state of the card.
     */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => self::DISABLED,
            $this->expires_at !== null && $this->expires_at->isPast() => self::EXPIRED,
            $this->balanceMoney()->isZero() => self::USED_UP,
            default => self::ACTIVE,
        };
    }
}
