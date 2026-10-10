<?php

use App\Http\Controllers\Admin\ACLController as AdminACLController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BadgeController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BillingWebhookCallController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BlogCommentController as AdminBlogCommentController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\BlogTagController;
use App\Http\Controllers\Admin\Catalogue\OptionController;
use App\Http\Controllers\Admin\Catalogue\PriceController;
use App\Http\Controllers\Admin\Catalogue\ProductController;
use App\Http\Controllers\Admin\Catalogue\ProductFileController;
use App\Http\Controllers\Admin\Catalogue\ProductImageController;
use App\Http\Controllers\Admin\Catalogue\StockController;
use App\Http\Controllers\Admin\Catalogue\TaxonomyController;
use App\Http\Controllers\Admin\Catalogue\VariantController;
use App\Http\Controllers\Admin\CommerceController;
use App\Http\Controllers\Admin\CommerceOrderController;
use App\Http\Controllers\Admin\CommerceOrderDownloadController;
use App\Http\Controllers\Admin\CommerceOrderRefundController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\FaqCategoryController;
use App\Http\Controllers\Admin\ForumBoardController;
use App\Http\Controllers\Admin\ForumCategoryController;
use App\Http\Controllers\Admin\ForumReportController;
use App\Http\Controllers\Admin\PollController;
use App\Http\Controllers\Admin\SearchAnalyticsController;
use App\Http\Controllers\Admin\ShippingRateController;
use App\Http\Controllers\Admin\ShippingZoneController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SupportAssignmentRuleController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SupportTicketCategoryController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\TaxRateController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\TrustSafetyController;
use App\Http\Controllers\Admin\UsersController as AdminUserController;
use App\Http\Controllers\Admin\UserSocialAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin|editor|moderator'])->group(function () {
    Route::redirect('acp', '/acp/dashboard');

    Route::get('acp/dashboard', [AdminController::class, 'get'])->name('acp.dashboard');
    Route::get('acp/search-analytics', [SearchAnalyticsController::class, 'index'])
        ->middleware('can:search.acp.view')
        ->name('acp.search-analytics.index');
    Route::get('acp/search-analytics/exports/aggregates', [SearchAnalyticsController::class, 'exportAggregates'])
        ->middleware('can:search.acp.view')
        ->name('acp.search-analytics.export-aggregates');
    Route::get('acp/search-analytics/exports/searches', [SearchAnalyticsController::class, 'exportSearches'])
        ->middleware('can:search.acp.view')
        ->name('acp.search-analytics.export-searches');

    // Admin User Management Routes
    Route::get('acp/users', [AdminUserController::class, 'index'])->name('acp.users.index');
    Route::get('acp/users/{user}/edit', [AdminUserController::class, 'edit'])->name('acp.users.edit');
    Route::put('acp/users/{user}', [AdminUserController::class, 'update'])->name('acp.users.update');
    Route::delete('acp/users/{user}', [AdminUserController::class, 'destroy'])->name('acp.users.destroy');
    Route::put('acp/users/{user}/verify', [AdminUserController::class, 'verify'])->name('acp.users.verify');
    Route::put('acp/users/{user}/ban', [AdminUserController::class, 'ban'])->name('acp.users.ban');
    Route::put('acp/users/{user}/unban', [AdminUserController::class, 'unban'])->name('acp.users.unban');
    Route::patch('acp/users/bulk', [AdminUserController::class, 'bulkUpdate'])->name('acp.users.bulk-update');
    Route::post('acp/users/{user}/social-accounts', [UserSocialAccountController::class, 'store'])
        ->name('acp.users.social-accounts.store');
    Route::delete('acp/users/{user}/social-accounts/{socialAccount}', [UserSocialAccountController::class, 'destroy'])
        ->name('acp.users.social-accounts.destroy');

    // Admin Access Control Management Routes
    Route::get('acp/acl', [AdminACLController::class, 'index'])->name('acp.acl.index');
    Route::get('acp/acl/permissions/create', [AdminACLController::class, 'createPermission'])->name('acp.acl.permissions.create');
    Route::post('acp/acl/permissions', [AdminACLController::class, 'storePermission'])->name('acp.acl.permissions.store');
    Route::put('acp/acl/permissions/{permission}', [AdminACLController::class, 'updatePermission'])->name('acp.acl.permissions.update');
    Route::delete('acp/acl/permissions/{permission}', [AdminACLController::class, 'destroyPermission'])->name('acp.acl.permissions.destroy');
    Route::post('acp/acl/roles', [AdminACLController::class, 'storeRole'])->name('acp.acl.roles.store');
    Route::get('acp/acl/roles/create', [AdminACLController::class, 'createRole'])->name('acp.acl.roles.create');
    Route::put('acp/acl/roles/{role}', [AdminACLController::class, 'updateRole'])->name('acp.acl.roles.update');
    Route::delete('acp/acl/roles/{role}', [AdminACLController::class, 'destroyRole'])->name('acp.acl.roles.destroy');

    // Admin Blog Management Routes
    Route::get('acp/blogs', [AdminBlogController::class, 'index'])->name('acp.blogs.index');
    Route::get('acp/blogs/create', [AdminBlogController::class, 'create'])->name('acp.blogs.create');
    Route::post('acp/blogs', [AdminBlogController::class, 'store'])->name('acp.blogs.store');
    Route::get('acp/blogs/{blog}/edit', [AdminBlogController::class, 'edit'])->name('acp.blogs.edit');
    Route::put('acp/blogs/{blog}', [AdminBlogController::class, 'update'])->name('acp.blogs.update');
    Route::delete('acp/blogs/{blog}', [AdminBlogController::class, 'destroy'])->name('acp.blogs.destroy');
    Route::put('acp/blogs/{blog}/publish', [AdminBlogController::class, 'publish'])->name('acp.blogs.publish');
    Route::put('acp/blogs/{blog}/unpublish', [AdminBlogController::class, 'unpublish'])->name('acp.blogs.unpublish');
    Route::put('acp/blogs/{blog}/archive', [AdminBlogController::class, 'archive'])->name('acp.blogs.archive');
    Route::put('acp/blogs/{blog}/unarchive', [AdminBlogController::class, 'unarchive'])->name('acp.blogs.unarchive');
    Route::put('acp/blogs/{blog}/comments/enable', [AdminBlogController::class, 'enableComments'])
        ->name('acp.blogs.comments.enable');
    Route::put('acp/blogs/{blog}/comments/disable', [AdminBlogController::class, 'disableComments'])
        ->name('acp.blogs.comments.disable');
    Route::patch('acp/blogs/bulk/status', [AdminBlogController::class, 'bulkUpdateStatus'])->name('acp.blogs.bulk-status');

    Route::get('acp/blog-comments', [AdminBlogCommentController::class, 'index'])->name('acp.blog-comments.index');
    Route::put('acp/blog-comments/{comment}', [AdminBlogCommentController::class, 'update'])->name('acp.blog-comments.update');
    Route::delete('acp/blog-comments/{comment}', [AdminBlogCommentController::class, 'destroy'])->name('acp.blog-comments.destroy');
    Route::patch('acp/blog-comment-reports/bulk-status', [AdminBlogCommentController::class, 'bulkUpdateReportStatus'])
        ->name('acp.blog-comment-reports.bulk-status');

    // Admin Blog Tag Management Routes
    Route::get('acp/blog-tags', [BlogTagController::class, 'index'])->name('acp.blog-tags.index');
    Route::get('acp/blog-tags/create', [BlogTagController::class, 'create'])->name('acp.blog-tags.create');
    Route::post('acp/blog-tags', [BlogTagController::class, 'store'])->name('acp.blog-tags.store');
    Route::get('acp/blog-tags/{tag}/edit', [BlogTagController::class, 'edit'])->name('acp.blog-tags.edit');
    Route::put('acp/blog-tags/{tag}', [BlogTagController::class, 'update'])->name('acp.blog-tags.update');
    Route::delete('acp/blog-tags/{tag}', [BlogTagController::class, 'destroy'])->name('acp.blog-tags.destroy');

    // Admin Blog Category Management Routes
    Route::get('acp/blog-categories', [BlogCategoryController::class, 'index'])->name('acp.blog-categories.index');
    Route::get('acp/blog-categories/create', [BlogCategoryController::class, 'create'])->name('acp.blog-categories.create');
    Route::post('acp/blog-categories', [BlogCategoryController::class, 'store'])->name('acp.blog-categories.store');
    Route::get('acp/blog-categories/{category}/edit', [BlogCategoryController::class, 'edit'])->name('acp.blog-categories.edit');
    Route::put('acp/blog-categories/{category}', [BlogCategoryController::class, 'update'])->name('acp.blog-categories.update');
    Route::delete('acp/blog-categories/{category}', [BlogCategoryController::class, 'destroy'])->name('acp.blog-categories.destroy');

    Route::get('acp/forums', [ForumCategoryController::class, 'index'])->name('acp.forums.index');
    Route::get('acp/forums/reports', [ForumReportController::class, 'index'])->name('acp.forums.reports.index');
    Route::patch('acp/forums/reports/bulk/status', [ForumReportController::class, 'bulkUpdateStatus'])->name('acp.forums.reports.bulk-status');
    Route::patch('acp/forums/reports/threads/{report}', [ForumReportController::class, 'updateThread'])->name('acp.forums.reports.threads.update');
    Route::patch('acp/forums/reports/posts/{report}', [ForumReportController::class, 'updatePost'])->name('acp.forums.reports.posts.update');
    Route::get('acp/forums/categories/create', [ForumCategoryController::class, 'create'])->name('acp.forums.categories.create');
    Route::post('acp/forums/categories', [ForumCategoryController::class, 'store'])->name('acp.forums.categories.store');
    Route::get('acp/forums/categories/{category}/edit', [ForumCategoryController::class, 'edit'])->name('acp.forums.categories.edit');
    Route::put('acp/forums/categories/{category}', [ForumCategoryController::class, 'update'])->name('acp.forums.categories.update');
    Route::delete('acp/forums/categories/{category}', [ForumCategoryController::class, 'destroy'])->name('acp.forums.categories.destroy');
    Route::patch('acp/forums/categories/{category}/reorder', [ForumCategoryController::class, 'reorder'])->name('acp.forums.categories.reorder');

    Route::get('acp/forums/boards/create', [ForumBoardController::class, 'create'])->name('acp.forums.boards.create');
    Route::post('acp/forums/boards', [ForumBoardController::class, 'store'])->name('acp.forums.boards.store');
    Route::get('acp/forums/boards/{board:id}/edit', [ForumBoardController::class, 'edit'])->name('acp.forums.boards.edit');
    Route::put('acp/forums/boards/{board:id}', [ForumBoardController::class, 'update'])->name('acp.forums.boards.update');
    Route::delete('acp/forums/boards/{board:id}', [ForumBoardController::class, 'destroy'])->name('acp.forums.boards.destroy');
    Route::patch('acp/forums/boards/{board:id}/reorder', [ForumBoardController::class, 'reorder'])->name('acp.forums.boards.reorder');

    Route::get('acp/trust-safety', [TrustSafetyController::class, 'index'])->name('acp.trust-safety.index');
    Route::patch('acp/trust-safety/exports/{export}', [TrustSafetyController::class, 'updateExport'])->name('acp.trust-safety.exports.update');
    Route::patch('acp/trust-safety/erasure-requests/{erasureRequest}', [TrustSafetyController::class, 'updateErasure'])->name('acp.trust-safety.erasure.update');

    Route::middleware(['section.enabled:commerce', 'can:commerce.acp.view'])
        ->prefix('acp/commerce')
        ->name('acp.commerce.')
        ->group(function () {
            Route::get('/', [CommerceController::class, 'index'])->name('index');
            // Products, and everything edited from a product's page.
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/create', [ProductController::class, 'create'])
                ->middleware('can:commerce.acp.create')
                ->name('products.create');
            Route::post('products', [ProductController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('products.store');
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('products.destroy');

            Route::post('products/{product}/options', [OptionController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('options.store');
            Route::put('options/{option}', [OptionController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('options.update');
            Route::delete('options/{option}', [OptionController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('options.destroy');
            Route::post('options/{option}/values', [OptionController::class, 'storeValue'])
                ->middleware('can:commerce.acp.create')
                ->name('option-values.store');
            Route::put('option-values/{value}', [OptionController::class, 'updateValue'])
                ->middleware('can:commerce.acp.edit')
                ->name('option-values.update');
            Route::delete('option-values/{value}', [OptionController::class, 'destroyValue'])
                ->middleware('can:commerce.acp.delete')
                ->name('option-values.destroy');

            Route::post('products/{product}/variants', [VariantController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('variants.store');
            Route::post('products/{product}/variants/generate', [VariantController::class, 'generate'])
                ->middleware('can:commerce.acp.create')
                ->name('variants.generate');
            Route::put('variants/{variant}', [VariantController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('variants.update');
            Route::delete('variants/{variant}', [VariantController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('variants.destroy');

            Route::post('products/{product}/prices', [PriceController::class, 'storeForProduct'])
                ->middleware('can:commerce.acp.create')
                ->name('prices.store');
            Route::post('variants/{variant}/prices', [PriceController::class, 'storeForVariant'])
                ->middleware('can:commerce.acp.create')
                ->name('variants.prices.store');
            Route::put('prices/{price}', [PriceController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('prices.update');
            Route::delete('prices/{price}', [PriceController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('prices.destroy');

            Route::post('products/{product}/stock', [StockController::class, 'track'])
                ->middleware('can:commerce.acp.create')
                ->name('stock.track');
            Route::put('stock/{item}', [StockController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('stock.update');
            Route::delete('stock/{item}', [StockController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('stock.destroy');

            // A product's pictures.
            Route::post('products/{product}/images', [ProductImageController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('images.store');
            Route::post('products/{product}/images/order', [ProductImageController::class, 'reorder'])
                ->middleware('can:commerce.acp.edit')
                ->name('images.reorder');
            Route::put('images/{image}', [ProductImageController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('images.update');
            Route::post('images/{image}/main', [ProductImageController::class, 'makeMain'])
                ->middleware('can:commerce.acp.edit')
                ->name('images.main');
            Route::delete('images/{image}', [ProductImageController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('images.destroy');

            // The files a product delivers once it is paid for.
            Route::post('products/{product}/files', [ProductFileController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('files.store');
            Route::put('files/{file}', [ProductFileController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('files.update');
            Route::post('files/{file}/replace', [ProductFileController::class, 'replace'])
                ->middleware('can:commerce.acp.edit')
                ->name('files.replace');
            Route::delete('files/{file}', [ProductFileController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('files.destroy');

            // Brands, categories and tags.
            Route::get('taxonomy', [TaxonomyController::class, 'index'])->name('taxonomy.index');
            Route::post('taxonomy/{type}', [TaxonomyController::class, 'store'])
                ->where('type', 'brands|categories|tags')
                ->middleware('can:commerce.acp.create')
                ->name('taxonomy.store');
            Route::put('taxonomy/{type}/{id}', [TaxonomyController::class, 'update'])
                ->where('type', 'brands|categories|tags')
                ->whereNumber('id')
                ->middleware('can:commerce.acp.edit')
                ->name('taxonomy.update');
            Route::delete('taxonomy/{type}/{id}', [TaxonomyController::class, 'destroy'])
                ->where('type', 'brands|categories|tags')
                ->whereNumber('id')
                ->middleware('can:commerce.acp.delete')
                ->name('taxonomy.destroy');

            // Shipping zones and their rates.
            Route::get('shipping', [ShippingZoneController::class, 'index'])->name('shipping.index');
            Route::post('shipping/zones', [ShippingZoneController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('shipping.zones.store');
            Route::put('shipping/zones/{zone}', [ShippingZoneController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('shipping.zones.update');
            Route::delete('shipping/zones/{zone}', [ShippingZoneController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('shipping.zones.destroy');
            Route::post('shipping/zones/{zone}/rates', [ShippingRateController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('shipping.rates.store');
            Route::put('shipping/rates/{rate}', [ShippingRateController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('shipping.rates.update');
            Route::delete('shipping/rates/{rate}', [ShippingRateController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('shipping.rates.destroy');

            // Orders: find them, fulfil them, keep notes. Refunds have their own permission.
            Route::get('orders', [CommerceOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [CommerceOrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/fulfil', [CommerceOrderController::class, 'fulfil'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.fulfil');
            Route::post('orders/{order}/cancel', [CommerceOrderController::class, 'cancel'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.cancel');
            Route::post('orders/{order}/notes', [CommerceOrderController::class, 'storeNote'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.notes.store');
            Route::post('orders/{order}/downloads/{grant}/reset', [CommerceOrderDownloadController::class, 'reset'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.downloads.reset');
            Route::post('orders/{order}/downloads/{grant}/revoke', [CommerceOrderDownloadController::class, 'revoke'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.downloads.revoke');
            Route::post('orders/{order}/downloads/{grant}/restore', [CommerceOrderDownloadController::class, 'restore'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.downloads.restore');
            Route::post('orders/{order}/check-payment', [CommerceOrderController::class, 'checkPayment'])
                ->middleware('can:commerce.acp.edit')
                ->name('orders.check-payment');
            Route::post('orders/{order}/refunds', [CommerceOrderRefundController::class, 'store'])
                ->middleware('can:commerce.acp.refund')
                ->name('orders.refunds.store');
            Route::post('orders/{order}/refunds/{refund}/check', [CommerceOrderRefundController::class, 'check'])
                ->middleware('can:commerce.acp.refund')
                ->name('orders.refunds.check');

            // Discount codes.
            Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
            Route::get('coupons/create', [CouponController::class, 'create'])
                ->middleware('can:commerce.acp.create')
                ->name('coupons.create');
            Route::get('coupons/product-search', [CouponController::class, 'productSearch'])->name('coupons.product-search');
            Route::post('coupons', [CouponController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('coupons.store');
            Route::get('coupons/{coupon}/edit', [CouponController::class, 'edit'])->name('coupons.edit');
            Route::put('coupons/{coupon}', [CouponController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('coupons.update');
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('coupons.destroy');

            // The tax table.
            Route::get('tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
            Route::post('tax-rates', [TaxRateController::class, 'store'])
                ->middleware('can:commerce.acp.create')
                ->name('tax-rates.store');
            Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'update'])
                ->middleware('can:commerce.acp.edit')
                ->name('tax-rates.update');
            Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])
                ->middleware('can:commerce.acp.delete')
                ->name('tax-rates.destroy');
        });

    // Support ACP
    Route::get('acp/support', [SupportController::class, 'index'])->name('acp.support.index');
    Route::get('acp/support/users/search', [SupportController::class, 'searchUsers'])->name('acp.support.users.search');

    Route::get('acp/support/sla', [SupportController::class, 'sla'])->name('acp.support.sla.index');
    Route::put('acp/support/sla', [SupportController::class, 'updateSla'])->name('acp.support.sla.update');

    Route::get('acp/support/templates', [SupportController::class, 'templates'])->name('acp.support.templates.index');
    Route::post('acp/support/templates', [SupportController::class, 'storeTemplate'])->name('acp.support.templates.store');
    Route::put('acp/support/templates/{template}', [SupportController::class, 'updateTemplate'])->name('acp.support.templates.update');
    Route::delete('acp/support/templates/{template}', [SupportController::class, 'destroyTemplate'])->name('acp.support.templates.destroy');

    Route::get('acp/support/assignment-rules', [SupportAssignmentRuleController::class, 'index'])
        ->name('acp.support.assignment-rules.index');
    Route::post('acp/support/assignment-rules', [SupportAssignmentRuleController::class, 'store'])
        ->name('acp.support.assignment-rules.store');
    Route::put('acp/support/assignment-rules/{rule}', [SupportAssignmentRuleController::class, 'update'])
        ->name('acp.support.assignment-rules.update');
    Route::delete('acp/support/assignment-rules/{rule}', [SupportAssignmentRuleController::class, 'destroy'])
        ->name('acp.support.assignment-rules.destroy');
    Route::patch('acp/support/assignment-rules/{rule}/reorder', [SupportAssignmentRuleController::class, 'reorder'])
        ->name('acp.support.assignment-rules.reorder');

    Route::get('acp/support/teams', [SupportController::class, 'teams'])->name('acp.support.teams.index');
    Route::post('acp/support/teams', [SupportController::class, 'storeTeam'])->name('acp.support.teams.store');
    Route::put('acp/support/teams/{team}', [SupportController::class, 'updateTeam'])->name('acp.support.teams.update');
    Route::delete('acp/support/teams/{team}', [SupportController::class, 'destroyTeam'])->name('acp.support.teams.destroy');
    Route::put('acp/support/teams/memberships/{user}', [SupportController::class, 'updateTeamMembership'])
        ->name('acp.support.teams.memberships.update');

    // Tickets
    Route::get('acp/support/tickets/create', [SupportController::class, 'createTicket'])->name('acp.support.tickets.create');
    Route::get('acp/support/tickets/{ticket}', [SupportController::class, 'showTicket'])->name('acp.support.tickets.show');
    Route::get('acp/support/tickets/{ticket}/edit', [SupportController::class, 'editTicket'])->name('acp.support.tickets.edit');
    Route::post('acp/support/tickets', [SupportController::class, 'storeTicket'])->name('acp.support.tickets.store');
    Route::post('acp/support/tickets/{ticket}/messages', [SupportController::class, 'storeTicketMessage'])->name('acp.support.tickets.messages.store');
    Route::put('acp/support/tickets/{ticket}', [SupportController::class, 'updateTicket'])->name('acp.support.tickets.update');
    Route::delete('acp/support/tickets/{ticket}', [SupportController::class, 'destroyTicket'])->name('acp.support.tickets.destroy');
    Route::put('acp/support/tickets/{ticket}/assign', [SupportController::class, 'assignTicket'])->name('acp.support.tickets.assign');
    Route::put('acp/support/tickets/{ticket}/priority', [SupportController::class, 'updateTicketPriority'])->name('acp.support.tickets.priority');
    Route::put('acp/support/tickets/{ticket}/status', [SupportController::class, 'updateTicketStatus'])->name('acp.support.tickets.status');
    Route::patch('acp/support/tickets/bulk/status', [SupportController::class, 'bulkUpdateStatus'])->name('acp.support.tickets.bulk-status');

    // Ticket categories
    Route::get('acp/support/ticket-categories', [SupportTicketCategoryController::class, 'index'])->name('acp.support.ticket-categories.index');
    Route::get('acp/support/ticket-categories/create', [SupportTicketCategoryController::class, 'create'])->name('acp.support.ticket-categories.create');
    Route::post('acp/support/ticket-categories', [SupportTicketCategoryController::class, 'store'])->name('acp.support.ticket-categories.store');
    Route::get('acp/support/ticket-categories/{category}/edit', [SupportTicketCategoryController::class, 'edit'])->name('acp.support.ticket-categories.edit');
    Route::put('acp/support/ticket-categories/{category}', [SupportTicketCategoryController::class, 'update'])->name('acp.support.ticket-categories.update');
    Route::delete('acp/support/ticket-categories/{category}', [SupportTicketCategoryController::class, 'destroy'])->name('acp.support.ticket-categories.destroy');

    // FAQs
    Route::get('acp/support/faqs/create', [SupportController::class, 'createFaq'])->name('acp.support.faqs.create');
    Route::get('acp/support/faqs/{faq}/edit', [SupportController::class, 'editFaq'])->name('acp.support.faqs.edit');
    Route::post('acp/support/faqs', [SupportController::class, 'storeFaq'])->name('acp.support.faqs.store');
    Route::put('acp/support/faqs/{faq}', [SupportController::class, 'updateFaq'])->name('acp.support.faqs.update');
    Route::delete('acp/support/faqs/{faq}', [SupportController::class, 'destroyFaq'])->name('acp.support.faqs.destroy');
    Route::patch('acp/support/faqs/{faq}/reorder', [SupportController::class, 'reorderFaq'])->name('acp.support.faqs.reorder');
    Route::patch('acp/support/faqs/{faq}/publish', [SupportController::class, 'publishFaq'])->name('acp.support.faqs.publish');
    Route::patch('acp/support/faqs/{faq}/unpublish', [SupportController::class, 'unpublishFaq'])->name('acp.support.faqs.unpublish');

    // FAQ Categories
    Route::get('acp/support/faq-categories', [FaqCategoryController::class, 'index'])->name('acp.support.faq-categories.index');
    Route::get('acp/support/faq-categories/create', [FaqCategoryController::class, 'create'])->name('acp.support.faq-categories.create');
    Route::post('acp/support/faq-categories', [FaqCategoryController::class, 'store'])->name('acp.support.faq-categories.store');
    Route::get('acp/support/faq-categories/{category}/edit', [FaqCategoryController::class, 'edit'])->name('acp.support.faq-categories.edit');
    Route::put('acp/support/faq-categories/{category}', [FaqCategoryController::class, 'update'])->name('acp.support.faq-categories.update');
    Route::delete('acp/support/faq-categories/{category}', [FaqCategoryController::class, 'destroy'])->name('acp.support.faq-categories.destroy');

    Route::get('acp/system', [SystemSettingsController::class, 'index'])
        ->middleware('can:system.acp.view')
        ->name('acp.system');
    Route::put('acp/system', [SystemSettingsController::class, 'update'])
        ->middleware('can:system.acp.edit')
        ->name('acp.system.update');

    Route::get('acp/reputation/badges', [BadgeController::class, 'index'])->name('acp.reputation.badges.index');
    Route::get('acp/reputation/badges/create', [BadgeController::class, 'create'])->name('acp.reputation.badges.create');
    Route::post('acp/reputation/badges', [BadgeController::class, 'store'])->name('acp.reputation.badges.store');
    Route::get('acp/reputation/badges/{badge}/edit', [BadgeController::class, 'edit'])->name('acp.reputation.badges.edit');
    Route::put('acp/reputation/badges/{badge}', [BadgeController::class, 'update'])->name('acp.reputation.badges.update');
    Route::delete('acp/reputation/badges/{badge}', [BadgeController::class, 'destroy'])->name('acp.reputation.badges.destroy');

    // Polls
    Route::get('acp/polls', [PollController::class, 'index'])
        ->middleware('can:polls.acp.view')
        ->name('acp.polls.index');
    Route::get('acp/polls/create', [PollController::class, 'create'])
        ->middleware('can:polls.acp.create')
        ->name('acp.polls.create');
    Route::post('acp/polls', [PollController::class, 'store'])
        ->middleware('can:polls.acp.create')
        ->name('acp.polls.store');
    Route::get('acp/polls/{poll}/edit', [PollController::class, 'edit'])
        ->middleware('can:polls.acp.edit')
        ->name('acp.polls.edit');
    Route::put('acp/polls/{poll}', [PollController::class, 'update'])
        ->middleware('can:polls.acp.edit')
        ->name('acp.polls.update');
    Route::delete('acp/polls/{poll}', [PollController::class, 'destroy'])
        ->middleware('can:polls.acp.delete')
        ->name('acp.polls.destroy');

    // Tokens
    Route::get('acp/tokens', [TokenController::class, 'index'])->name('acp.tokens.index');
    Route::post('acp/tokens', [TokenController::class, 'store'])->name('acp.tokens.store');
    Route::put('acp/tokens/{token}', [TokenController::class, 'update'])->name('acp.tokens.update');
    Route::patch('acp/tokens/{token}/revoke', [TokenController::class, 'revoke'])->name('acp.tokens.revoke');
    Route::delete('acp/tokens/{token}', [TokenController::class, 'destroy'])->name('acp.tokens.destroy');

    Route::get('acp/tokens/logs/{tokenLog}', [TokenController::class, 'showLog'])
        ->name('acp.tokens.logs.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('acp/blogs/{blog}/revisions', [AdminBlogController::class, 'revisions'])
        ->name('acp.blogs.revisions.index');
    Route::put('acp/blogs/{blog}/revisions/{revision}', [AdminBlogController::class, 'restoreRevision'])
        ->name('acp.blogs.revisions.restore');
});

Route::middleware(['auth', 'can:billing.acp.view'])
    ->prefix('acp/billing')
    ->name('acp.billing.')
    ->group(function () {
        Route::get('invoices', [BillingController::class, 'invoices'])->name('invoices.index');

        Route::get('webhooks', [BillingWebhookCallController::class, 'index'])->name('webhooks.index');
        Route::get('webhooks/{billingWebhookCall}', [BillingWebhookCallController::class, 'show'])
            ->name('webhooks.show');
        Route::post('webhooks/{billingWebhookCall}/replay', [BillingWebhookCallController::class, 'replay'])
            ->name('webhooks.replay');

        Route::get('plans', [SubscriptionPlanController::class, 'index'])->name('plans.index');
        Route::get('plans/create', [SubscriptionPlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [SubscriptionPlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}/edit', [SubscriptionPlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}', [SubscriptionPlanController::class, 'update'])->name('plans.update');
        Route::delete('plans/{plan}', [SubscriptionPlanController::class, 'destroy'])->name('plans.destroy');
    });
