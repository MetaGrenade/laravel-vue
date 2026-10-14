<?php

namespace App\Support\Commerce\Reviews;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The rules of product reviews, in one place: who may write one, which ones wait for staff, and what a
 * product's rating is made of.
 *
 * Only published reviews count towards a rating or are shown. A review is "verified" when its writer has
 * a paid order for the product (one that was cancelled, or refunded in full, does not count), which is
 * worked out when the review is written and kept with it.
 */
class ProductReviews
{
    /** Why someone cannot write a review. */
    public const DISABLED = 'disabled';

    public const LOGIN = 'login';

    public const BANNED = 'banned';

    public const VERIFY_EMAIL = 'verify_email';

    public const PURCHASE = 'purchase';

    /** The orders a public review list can be put in. */
    public const SORTS = ['newest', 'highest', 'lowest'];

    public function enabled(): bool
    {
        return (bool) config('commerce.reviews.enabled', true);
    }

    /**
     * Why this person cannot review this product right now, or null when they can.
     */
    public function whyNot(?User $user, Product $product): ?string
    {
        if (! $this->enabled()) {
            return self::DISABLED;
        }

        if ($user === null) {
            return self::LOGIN;
        }

        if ($user->is_banned) {
            return self::BANNED;
        }

        if (EmailVerification::isRequired() && ! $user->hasVerifiedEmail()) {
            return self::VERIFY_EMAIL;
        }

        if (config('commerce.reviews.require_purchase') && ! $this->hasPurchased($user, $product)) {
            return self::PURCHASE;
        }

        return null;
    }

    /**
     * What to tell someone who cannot write a review.
     */
    public function explain(string $reason): string
    {
        return match ($reason) {
            self::LOGIN => 'Sign in to write a review.',
            self::BANNED => 'Your account cannot write reviews.',
            self::VERIFY_EMAIL => 'Confirm your email address to write a review.',
            self::PURCHASE => 'Only customers who bought this product can review it.',
            default => 'Reviews are switched off.',
        };
    }

    /**
     * Whether the user has a paid order for the product: the money was received, it was not given back in
     * full, and the order was not cancelled. (The order belongs to them, not just to someone who typed
     * their email.)
     */
    public function hasPurchased(User $user, Product $product): bool
    {
        return Order::query()
            ->visibleTo($user)
            ->whereIn('payment_status', [OrderPaymentStatus::Paid->value, OrderPaymentStatus::PartiallyRefunded->value])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('items', fn ($items) => $items->where('product_id', $product->id))
            ->exists();
    }

    /**
     * Whether a new (or changed) review is published at once or waits for staff.
     */
    public function statusFor(bool $verifiedPurchase): ReviewStatus
    {
        return match ((string) config('commerce.reviews.approval', 'unverified')) {
            'none' => ReviewStatus::Published,
            'unverified' => $verifiedPurchase ? ReviewStatus::Published : ReviewStatus::Pending,
            // "all", and anything that is not recognised: nothing goes public by accident.
            default => ReviewStatus::Pending,
        };
    }

    /**
     * Write the user's review of the product, or change the one they already wrote. Whether it needs
     * approval is decided again each time, and any earlier approval is forgotten, because staff approved
     * the text that was there before.
     *
     * @param  array{rating: int, title?: string|null, body: string}  $data
     */
    public function save(User $user, Product $product, array $data): ProductReview
    {
        try {
            // In a transaction of its own, so that a failed insert leaves nothing half done for the retry.
            return DB::transaction(fn () => $this->write($user, $product, $data));
        } catch (UniqueConstraintViolationException) {
            // A double click: the other request wrote it first, so this one changes what it wrote.
            return DB::transaction(fn () => $this->write($user, $product, $data));
        }
    }

    /**
     * @param  array{rating: int, title?: string|null, body: string}  $data
     */
    private function write(User $user, Product $product, array $data): ProductReview
    {
        $verified = $this->hasPurchased($user, $product);

        $review = ProductReview::query()->firstOrNew(['product_id' => $product->id, 'user_id' => $user->id]);

        $review->fill([
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'status' => $this->statusFor($verified),
            'verified_purchase' => $verified,
            'moderated_by' => null,
            'moderated_at' => null,
        ])->save();

        return $review;
    }

    /**
     * Published reviews of a product, in the order asked for (newest first by default).
     *
     * @return Builder<ProductReview>
     */
    public function published(Product $product, string $sort = 'newest'): Builder
    {
        $query = ProductReview::query()->published()->where('product_id', $product->id);

        match ($sort) {
            'highest' => $query->orderByDesc('rating'),
            'lowest' => $query->orderBy('rating'),
            default => null,
        };

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * What published reviews say about one product.
     *
     * @return array{count: int, average: float|null, distribution: array<int, int>}
     */
    public function summary(Product $product): array
    {
        return $this->summaries([$product->id])[$product->id] ?? $this->empty();
    }

    /**
     * What published reviews say about each of these products (in two queries however many there are).
     * A product with no published reviews is left out of the map.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, array{count: int, average: float|null, distribution: array<int, int>}>
     */
    public function summaries(iterable $productIds): array
    {
        $productIds = collect($productIds)->unique()->values();

        if ($productIds->isEmpty() || ! $this->enabled()) {
            return [];
        }

        $rows = ProductReview::query()
            ->published()
            ->whereIn('product_id', $productIds->all())
            ->selectRaw('product_id, rating, count(*) as aggregate')
            ->groupBy('product_id', 'rating')
            ->get();

        $summaries = [];

        foreach ($rows->groupBy('product_id') as $productId => $group) {
            $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

            foreach ($group as $row) {
                $distribution[(int) $row->rating] = (int) $row->getAttribute('aggregate');
            }

            $count = array_sum($distribution);
            $total = array_sum(array_map(fn (int $stars, int $number) => $stars * $number, array_keys($distribution), $distribution));

            $summaries[(int) $productId] = [
                'count' => $count,
                'average' => $count > 0 ? round($total / $count, 1) : null,
                'distribution' => $distribution,
            ];
        }

        return $summaries;
    }

    /**
     * The card-sized version of a summary, for a product listing: nothing at all for a product with no
     * reviews.
     *
     * @param  array<int, array{count: int, average: float|null, distribution: array<int, int>}>  $summaries
     * @return array{average: float, count: int}|null
     */
    public function rating(array $summaries, int $productId): ?array
    {
        $summary = $summaries[$productId] ?? null;

        if ($summary === null || $summary['count'] === 0 || $summary['average'] === null) {
            return null;
        }

        return ['average' => $summary['average'], 'count' => $summary['count']];
    }

    /**
     * @return array{count: int, average: float|null, distribution: array<int, int>}
     */
    private function empty(): array
    {
        return ['count' => 0, 'average' => null, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
    }
}
