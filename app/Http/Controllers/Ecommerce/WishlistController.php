<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Commerce\Reviews\ProductReviews;
use App\Support\Commerce\StorefrontProducts;
use App\Support\Commerce\WishlistException;
use App\Support\Commerce\Wishlists;
use App\Support\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A signed-in customer's saved products.
 */
class WishlistController extends Controller
{
    public function index(Request $request, Wishlists $wishlists, StorefrontProducts $storefront, ProductReviews $reviews): Response
    {
        $paginator = $wishlists->products($request->user())->paginate(24)->withQueryString();

        $summaries = $reviews->summaries($paginator->getCollection()->pluck('id'));

        $paginator->through(fn (Product $product) => [
            ...$storefront->card($product, $reviews->rating($summaries, $product->id)),
            'wishlisted' => true,
        ]);

        // A page of private, personal data: kept out of search engines.
        app(Seo::class)->title('Your wishlist')->noindex();

        return Inertia::render('commerce/Wishlist', ['products' => $paginator]);
    }

    public function store(Request $request, Wishlists $wishlists): RedirectResponse
    {
        $validated = $request->validate(['product_id' => ['required', 'integer']]);

        // A product that is not on sale cannot be saved (and nothing says whether it exists).
        $product = Product::query()->where('is_active', true)->find($validated['product_id']);

        if ($product === null) {
            return back()->with('error', 'This product is not available.');
        }

        try {
            $added = $wishlists->add($request->user(), $product);
        } catch (WishlistException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $added ? 'Saved to your wishlist.' : 'Already on your wishlist.');
    }

    public function destroy(Request $request, Wishlists $wishlists, Product $product): RedirectResponse
    {
        $wishlists->remove($request->user(), $product);

        return back()->with('success', 'Removed from your wishlist.');
    }
}
