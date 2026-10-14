<?php

namespace App\Policies;

use App\Enums\ReviewStatus;
use App\Models\ProductReview;
use App\Models\User;

/**
 * What a customer may do with their own review. A rejected review is staff's decision: it can be neither
 * changed nor deleted by its writer, or a rejection could be undone by writing the review again.
 * (Staff moderate through the ACP's own permissions.)
 */
class ProductReviewPolicy
{
    public function update(User $user, ProductReview $review): bool
    {
        return $user->id === $review->user_id && $review->status !== ReviewStatus::Rejected;
    }

    public function delete(User $user, ProductReview $review): bool
    {
        return $this->update($user, $review);
    }
}
