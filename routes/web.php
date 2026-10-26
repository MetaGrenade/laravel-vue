<?php

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogCommentSubscriptionController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CookieConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\Ecommerce\CartController;
use App\Http\Controllers\Ecommerce\CartCouponController;
use App\Http\Controllers\Ecommerce\CartGiftCardController;
use App\Http\Controllers\Ecommerce\CartPackageController;
use App\Http\Controllers\Ecommerce\CheckoutController;
use App\Http\Controllers\Ecommerce\DownloadController;
use App\Http\Controllers\Ecommerce\OrderController;
use App\Http\Controllers\Ecommerce\ProductCatalogController;
use App\Http\Controllers\Ecommerce\ProductReviewController;
use App\Http\Controllers\Ecommerce\TebexContinueController;
use App\Http\Controllers\Ecommerce\TebexStorefrontController;
use App\Http\Controllers\Ecommerce\WishlistController;
use App\Http\Controllers\ForumAttachmentController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\ForumFeedController;
use App\Http\Controllers\ForumIgnoreController;
use App\Http\Controllers\ForumPostController;
use App\Http\Controllers\ForumPostRevisionController;
use App\Http\Controllers\ForumThreadActionController;
use App\Http\Controllers\ForumThreadModerationController;
use App\Http\Controllers\ForumThreadSolutionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpersonationStopController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberFollowController;
use App\Http\Controllers\OnboardingDismissController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SearchResultsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportAttachmentController;
use App\Http\Controllers\SupportCenterController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\TebexWebhookController;
use App\Http\Controllers\WebManifestController;
use App\Support\Legal\LegalPages;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::view('/api/docs', 'api.docs')->name('api.docs');
Route::get('/api/docs/openapi.json', ApiDocumentationController::class)
    ->name('api.docs.schema');

// PUBLIC PAGES
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/site.webmanifest', WebManifestController::class)->name('webmanifest');

Route::get('/', HomeController::class)->name('home');

Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::get('members/{user}', [MemberController::class, 'show'])->whereNumber('user')->name('members.show');

Route::get('/search', SearchController::class)->middleware('throttle:search')->name('search');
Route::get('/search/results', SearchResultsController::class)->middleware('throttle:search')->name('search.results');
Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');

// Terms, the privacy policy and the cookie policy. A page with no text is not published (docs/legal-pages.md).
Route::post('legal/consent', [CookieConsentController::class, 'store'])
    ->middleware('throttle:interactions')
    ->name('legal.consent.store');
Route::get('legal/{page}', [LegalPageController::class, 'show'])
    ->whereIn('page', array_keys(LegalPages::PAGES))
    ->name('legal.show');
Route::middleware('throttle:billing')->group(function () {
    Route::post('/pricing/setup-intent', [PricingController::class, 'intent'])->name('pricing.intent');
    Route::post('/pricing/promotion-code', [PricingController::class, 'promotionCode'])
        ->middleware('throttle:promotion-codes')
        ->name('pricing.promotion-code');
    Route::post('/pricing/subscribe', [PricingController::class, 'subscribe'])
        ->middleware('throttle:promotion-codes')
        ->name('pricing.subscribe');
});

Route::middleware('section.enabled:commerce')->group(function () {
    Route::prefix('shop')->group(function () {
        Route::get('/', [ProductCatalogController::class, 'index'])->name('shop.index');
        Route::get('/packages', [TebexStorefrontController::class, 'index'])->name('shop.packages');
        Route::get('/products/{product:slug}', [ProductCatalogController::class, 'show'])
            ->name('shop.products.show');
        // A store that sells through Tebex shows its packages instead (the pages are only there while Tebex is the provider).
        Route::get('/packages/{package:tebex_id}', [TebexStorefrontController::class, 'show'])
            ->name('shop.packages.show');
        Route::post('/cart/packages', [CartPackageController::class, 'store'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.packages.store');
        Route::post('/cart/items', [CartController::class, 'store'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.items.store');
        Route::patch('/cart/items/{item}', [CartController::class, 'update'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.items.update');
        Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.items.destroy');
        // Codes can be tried one after another, so guessing them is slowed right down.
        Route::post('/cart/coupon', [CartCouponController::class, 'store'])
            ->middleware('throttle:coupons')
            ->name('shop.cart.coupon.store');
        Route::delete('/cart/coupon', [CartCouponController::class, 'destroy'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.coupon.destroy');
        // A gift card code is as guessable as a discount code is not (80 bits), but it is worth money,
        // so trying them is slowed right down all the same.
        Route::post('/cart/gift-card', [CartGiftCardController::class, 'store'])
            ->middleware('throttle:coupons')
            ->name('shop.cart.gift-card.store');
        Route::delete('/cart/gift-card', [CartGiftCardController::class, 'destroy'])
            ->middleware('throttle:interactions')
            ->name('shop.cart.gift-card.destroy');

        // A customer's own review of a product (one each: writing again changes it).
        Route::middleware('auth')->group(function () {
            Route::post('/products/{product:slug}/review', [ProductReviewController::class, 'store'])
                ->middleware('throttle:content')
                ->name('shop.products.review.store');
            Route::delete('/products/{product:slug}/review', [ProductReviewController::class, 'destroy'])
                ->middleware('throttle:interactions')
                ->name('shop.products.review.destroy');
        });
    });

    Route::get('/cart', [CartController::class, 'show'])->name('shop.cart');

    // Where a buyer of a Tebex store that makes them sign in is sent, and comes back to (signed, so a guest can use it).
    Route::get('/checkout/tebex/{order}', TebexContinueController::class)
        ->middleware(['signed', 'throttle:billing'])
        ->name('shop.tebex.continue');

    Route::middleware('throttle:billing')->group(function () {
        Route::get('/checkout', [CheckoutController::class, 'show'])->name('shop.checkout');
        Route::post('/checkout', [CheckoutController::class, 'store'])->name('shop.checkout.store');
    });

    Route::middleware('auth')->get('/orders', [OrderController::class, 'index'])->name('shop.orders');

    Route::middleware('auth')->prefix('wishlist')->group(function () {
        Route::get('/', [WishlistController::class, 'index'])->name('shop.wishlist');
        Route::post('/', [WishlistController::class, 'store'])
            ->middleware('throttle:interactions')
            ->name('shop.wishlist.store');
        Route::delete('/{product}', [WishlistController::class, 'destroy'])
            ->middleware('throttle:interactions')
            ->whereNumber('product')
            ->name('shop.wishlist.destroy');
    });
});

// Outside the shop toggle: a customer's receipt must keep working even if the shop is switched off.
Route::get('/checkout/complete/{order}', [CheckoutController::class, 'complete'])
    ->middleware('throttle:checkout-status')
    ->name('shop.checkout.complete');

// A purchased file. The signature (short-lived, made by the order page) is what authorises it, so a
// guest can use it; like the receipt it keeps working if the shop is switched off.
Route::get('/downloads/{grant}/{file}', [DownloadController::class, 'show'])
    ->middleware(['signed', 'throttle:downloads'])
    ->whereNumber('file')
    ->name('shop.downloads.show');

// Public Blog Routes
Route::middleware('section.enabled:blog')->group(function () {
    Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
    Route::get('/blogs/feed', [BlogController::class, 'feed'])->name('blogs.feed');
    Route::get('/blogs/preview/{blog}/{token}', [BlogController::class, 'preview'])->name('blogs.preview');
    Route::get('/blogs/{slug}', [BlogController::class, 'show'])->name('blogs.view');

    Route::prefix('blogs/{blog:slug}/comments')->group(function () {
        Route::get('/', [BlogCommentController::class, 'index'])->name('blogs.comments.index');

        Route::middleware('auth')->group(function () {
            Route::post('/', [BlogCommentController::class, 'store'])
                ->middleware('throttle:blog-comments')
                ->name('blogs.comments.store');
            Route::put('/{comment}', [BlogCommentController::class, 'update'])
                ->middleware('throttle:content')
                ->whereNumber('comment')
                ->name('blogs.comments.update');
            Route::delete('/{comment}', [BlogCommentController::class, 'destroy'])
                ->whereNumber('comment')
                ->name('blogs.comments.destroy');
            Route::post('/{comment}/report', [BlogCommentController::class, 'report'])
                ->whereNumber('comment')
                ->middleware(['verified', 'throttle:interactions'])
                ->name('blogs.comments.report');
            Route::post('/{comment}/react', [BlogCommentController::class, 'react'])
                ->middleware('throttle:interactions')
                ->whereNumber('comment')
                ->name('blogs.comments.react');
            Route::post('/subscriptions', [BlogCommentSubscriptionController::class, 'store'])
                ->name('blogs.comments.subscriptions.store');
            Route::delete('/subscriptions', [BlogCommentSubscriptionController::class, 'destroy'])
                ->name('blogs.comments.subscriptions.destroy');
        });
    });
});

Route::middleware('auth')->group(function () {
    Route::get('messages', [DirectMessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{user}', [DirectMessageController::class, 'show'])
        ->whereNumber('user')
        ->name('messages.show');
    Route::post('messages/{user}', [DirectMessageController::class, 'store'])
        ->whereNumber('user')
        ->middleware('throttle:content')
        ->name('messages.store');
    Route::post('notifications/read-all', [UserNotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [UserNotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::delete('notifications/{notification}', [UserNotificationController::class, 'destroy'])
        ->name('notifications.destroy');
});

Route::middleware('section.enabled:forum')->group(function () {
    Route::get('leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');
    Route::get('forum/attachments/{attachment}', [ForumAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->name('forum.attachments.show');
    Route::middleware('auth')->get('forum/following', [MemberFollowController::class, 'index'])
        ->name('forum.following');
    Route::get('forum/feed', [ForumFeedController::class, 'index'])->name('forum.feed');
    Route::get('forum', [ForumController::class, 'index'])->name('forum.index');
    Route::middleware(['auth', 'throttle:search'])->get('forum/mentions', [ForumController::class, 'mentionSuggestions'])
        ->name('forum.mentions.index');
    Route::get('forum/{board:slug}/feed', [ForumFeedController::class, 'board'])->name('forum.boards.feed');
    Route::get('forum/{board:slug}', [ForumController::class, 'showBoard'])->name('forum.boards.show');
    Route::get('forum/{board:slug}/{thread:slug}', [ForumController::class, 'showThread'])->name('forum.threads.show');

    Route::middleware('auth')->group(function () {
        Route::put('forum/ignores/{user}', [ForumIgnoreController::class, 'store'])
            ->whereNumber('user')
            ->name('forum.ignores.store');
        Route::delete('forum/ignores/{user}', [ForumIgnoreController::class, 'destroy'])
            ->whereNumber('user')
            ->name('forum.ignores.destroy');
        Route::put('forum/follows/{user}', [MemberFollowController::class, 'store'])
            ->whereNumber('user')
            ->name('forum.follows.store');
        Route::delete('forum/follows/{user}', [MemberFollowController::class, 'destroy'])
            ->whereNumber('user')
            ->name('forum.follows.destroy');
        Route::get('forum/{board:slug}/threads/create', [ForumController::class, 'createThread'])
            ->name('forum.threads.create');
        Route::post('forum/{board:slug}/threads', [ForumController::class, 'storeThread'])
            ->middleware('throttle:content')
            ->name('forum.threads.store');
        Route::post('forum/{board:slug}/{thread:slug}/report', [ForumThreadActionController::class, 'report'])
            ->middleware('throttle:interactions')
            ->name('forum.threads.report');
        Route::post('forum/{board:slug}/{thread:slug}/mark-read', [ForumThreadActionController::class, 'markAsRead'])
            ->name('forum.threads.mark-read');
        Route::post('forum/{board:slug}/mark-read', [ForumThreadActionController::class, 'markBoardAsRead'])
            ->name('forum.boards.mark-read');
        Route::post('forum/{board:slug}/{thread:slug}/subscribe', [ForumThreadActionController::class, 'subscribe'])
            ->name('forum.threads.subscribe');
        Route::delete('forum/{board:slug}/{thread:slug}/unsubscribe', [ForumThreadActionController::class, 'unsubscribe'])
            ->name('forum.threads.unsubscribe');
        Route::put('forum/{board:slug}/{thread:slug}', [ForumThreadModerationController::class, 'update'])
            ->name('forum.threads.update');
        Route::put('forum/{board:slug}/{thread:slug}/solution', [ForumThreadSolutionController::class, 'update'])
            ->name('forum.threads.solution');

        Route::post('forum/{board:slug}/{thread:slug}/posts', [ForumPostController::class, 'store'])
            ->middleware('throttle:content')
            ->name('forum.posts.store');
        Route::put('forum/{board:slug}/{thread:slug}/posts/{post}', [ForumPostController::class, 'update'])
            ->middleware('throttle:content')
            ->name('forum.posts.update');
        Route::delete('forum/{board:slug}/{thread:slug}/posts/{post}', [ForumPostController::class, 'destroy'])
            ->name('forum.posts.destroy');
        Route::post('forum/{board:slug}/{thread:slug}/posts/{post}/report', [ForumPostController::class, 'report'])
            ->middleware('throttle:interactions')
            ->name('forum.posts.report');
        Route::get('forum/{board:slug}/{thread:slug}/posts/{post}/history', [ForumPostRevisionController::class, 'index'])
            ->name('forum.posts.history');
        Route::put('forum/{board:slug}/{thread:slug}/posts/{post}/history/{revision}', [ForumPostRevisionController::class, 'restore'])
            ->name('forum.posts.history.restore');
    });

    Route::middleware(['auth', 'role:admin|editor|moderator'])->group(function () {
        Route::put('forum/{board:slug}/{thread:slug}/publish', [ForumThreadModerationController::class, 'publish'])
            ->name('forum.threads.publish');
        Route::put('forum/{board:slug}/{thread:slug}/unpublish', [ForumThreadModerationController::class, 'unpublish'])
            ->name('forum.threads.unpublish');
        Route::put('forum/{board:slug}/{thread:slug}/lock', [ForumThreadModerationController::class, 'lock'])
            ->name('forum.threads.lock');
        Route::put('forum/{board:slug}/{thread:slug}/unlock', [ForumThreadModerationController::class, 'unlock'])
            ->name('forum.threads.unlock');
        Route::put('forum/{board:slug}/{thread:slug}/pin', [ForumThreadModerationController::class, 'pin'])
            ->name('forum.threads.pin');
        Route::put('forum/{board:slug}/{thread:slug}/unpin', [ForumThreadModerationController::class, 'unpin'])
            ->name('forum.threads.unpin');
        Route::delete('forum/{board:slug}/{thread:slug}', [ForumThreadModerationController::class, 'destroy'])
            ->name('forum.threads.destroy');
    });
});

// Attachment downloads stay available to ticket owners and staff even if the public
// support section is switched off, so they sit outside the section toggle.
Route::middleware('auth')
    ->get('support/attachments/{attachment}', [SupportAttachmentController::class, 'download'])
    ->name('support.attachments.download');

Route::middleware('section.enabled:support')->group(function () {
    Route::get('support', [SupportCenterController::class, 'index'])->name('support');

    Route::middleware('auth')->group(function () {
        Route::post('support/tickets', [SupportCenterController::class, 'store'])
            ->middleware('throttle:content')
            ->name('support.tickets.store');

        Route::get('support/tickets/{ticket}', [SupportCenterController::class, 'show'])
            ->name('support.tickets.show');

        Route::post('support/tickets/{ticket}/messages', [SupportCenterController::class, 'storeMessage'])
            ->middleware('throttle:content')
            ->name('support.tickets.messages.store');

        Route::post('support/tickets/{ticket}/rating', [SupportCenterController::class, 'storeRating'])
            ->middleware('throttle:interactions')
            ->name('support.tickets.rating.store');

        Route::patch('support/tickets/{ticket}/status', [SupportCenterController::class, 'updateStatus'])
            ->name('support.tickets.status.update');

        Route::patch('support/tickets/{ticket}/reopen', [SupportCenterController::class, 'reopen'])
            ->name('support.tickets.reopen');

        Route::post('support/faqs/{faq}/feedback', [SupportCenterController::class, 'storeFaqFeedback'])
            ->middleware('throttle:interactions')
            ->whereNumber('faq')
            ->name('support.faqs.feedback.store');
    });
});

// AUTH REQUIRED PAGES
Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Hiding the "Get started" checklist on it (docs/onboarding.md).
Route::post('onboarding/dismiss', OnboardingDismissController::class)
    ->middleware(['auth', 'verified'])
    ->name('onboarding.dismiss');

// The way out of a read-only view as a customer (the bar at the top of its pages). The customer is who is signed in for the request,
// so `auth` passes; see ApplyImpersonation.
Route::post('impersonation/stop', ImpersonationStopController::class)
    ->middleware('auth')
    ->name('impersonation.stop');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::post('stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook')
    ->withoutMiddleware([
        PreventRequestForgery::class,
        VerifyWebhookSignature::class,
    ]);

// Tebex: checked by the sender's address and the body's signature, not by a session or a token.
Route::post('webhooks/tebex', TebexWebhookController::class)
    ->name('tebex.webhook')
    ->withoutMiddleware([PreventRequestForgery::class]);
