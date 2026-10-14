<?php

namespace App\Http\Controllers\Ecommerce;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Admin\CommerceReviewController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\ProductReviewRequest;
use App\Models\Product;
use App\Support\Commerce\Reviews\ProductReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A customer writing, changing and deleting their own review of a product. (Showing reviews is part of the
 * product page; deciding which are published is {@see CommerceReviewController}.)
 */
class ProductReviewController extends Controller
{
    /**
     * Write the review, or change the one already written: a customer has one review per product.
     */
    public function store(ProductReviewRequest $request, Product $product, ProductReviews $reviews): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $user = $request->user();
        $reason = $reviews->whyNot($user, $product);

        abort_if($reason !== null, 403, $reviews->explain((string) $reason));

        // A rejected review is refused inside save(), against the review as it is when it is changed.
        $review = $reviews->save($user, $product, $request->validated());

        return back()->with('success', $review->status === ReviewStatus::Published
            ? 'Thanks, your review is published.'
            : 'Thanks, your review will appear once it has been approved.');
    }

    public function destroy(Request $request, Product $product, ProductReviews $reviews): RedirectResponse
    {
        $reviews->remove($request->user(), $product);

        return back()->with('success', 'Your review was deleted.');
    }
}
