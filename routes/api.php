<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\BlogCommentController as ApiBlogCommentController;
use App\Http\Controllers\Api\V1\BlogCommentSubscriptionController as ApiBlogCommentSubscriptionController;
use App\Http\Controllers\Api\V1\ForumPostCommandController;
use App\Http\Controllers\Api\V1\ForumThreadController;
use App\Http\Controllers\Api\V1\ForumThreadCommandController;
use App\Http\Controllers\Api\V1\ForumThreadModerationController as ApiForumThreadModerationController;
use App\Http\Controllers\Api\V1\ForumThreadSubscriptionController;
use App\Http\Controllers\Api\V1\Support\SupportTicketController;
use App\Http\Controllers\Api\V1\Support\SupportTicketMessageController;
use App\Http\Controllers\Api\V1\Support\SupportTicketRatingController;
use App\Http\Controllers\Api\V1\Support\SupportTicketStatusController;
use App\Http\Controllers\Api\V1\UserProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All API routes are versioned. The first version (v1) exposes read-only
| endpoints for the public resources alongside authenticated routes that
| require a Sanctum token. Each authenticated endpoint still honours the
| custom token throttling and activity middleware already configured for
| the application.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/token', [AuthTokenController::class, 'store'])
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity'])->group(function () {
        Route::delete('/auth/token', [AuthTokenController::class, 'destroy'])
            ->name('auth.token.destroy');
        Route::get('/profile', UserProfileController::class)
            ->name('profile.show');
    });

    $blogCommentThrottle = app()->environment('testing') ? 'throttle:1000,1' : 'throttle:20,1';

    Route::middleware('section.enabled:blog')->group(function () use ($blogCommentThrottle) {
        Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
        Route::get('/blogs/{blog:slug}', [BlogController::class, 'show'])->name('blogs.show');

        Route::prefix('/blogs/{blog:slug}/comments')->group(function () use ($blogCommentThrottle) {
            Route::get('/', [ApiBlogCommentController::class, 'index'])->name('blogs.comments.index');

            Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity', $blogCommentThrottle])->group(function () {
                Route::post('/', [ApiBlogCommentController::class, 'store'])
                    ->middleware('throttle:blog-comments')
                    ->name('blogs.comments.store');
                Route::patch('/{comment}', [ApiBlogCommentController::class, 'update'])
                    ->whereNumber('comment')
                    ->name('blogs.comments.update');
                Route::delete('/{comment}', [ApiBlogCommentController::class, 'destroy'])
                    ->whereNumber('comment')
                    ->name('blogs.comments.destroy');
                Route::post('/{comment}/report', [ApiBlogCommentController::class, 'report'])
                    ->whereNumber('comment')
                    ->name('blogs.comments.report');
                Route::post('/{comment}/react', [ApiBlogCommentController::class, 'react'])
                    ->whereNumber('comment')
                    ->name('blogs.comments.react');

                Route::post('/subscriptions', [ApiBlogCommentSubscriptionController::class, 'store'])
                    ->name('blogs.comments.subscriptions.store');
                Route::delete('/subscriptions', [ApiBlogCommentSubscriptionController::class, 'destroy'])
                    ->name('blogs.comments.subscriptions.destroy');
            });
        });
    });

    Route::middleware('section.enabled:forum')->group(function () {
        Route::get('/forum/threads', [ForumThreadController::class, 'index'])
            ->name('forum.threads.index');
        Route::get('/forum/threads/{thread:slug}', [ForumThreadController::class, 'show'])
            ->name('forum.threads.show');

        Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity', 'throttle:30,1'])->group(function () {
            Route::post('/forum/boards/{board:slug}/threads', [ForumThreadCommandController::class, 'store'])
                ->name('forum.threads.store');

            Route::post('/forum/boards/{board:slug}/threads/{thread:slug}/posts', [ForumPostCommandController::class, 'store'])
                ->name('forum.posts.store');
            Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/posts/{post}', [ForumPostCommandController::class, 'update'])
                ->name('forum.posts.update');

            Route::post('/forum/boards/{board:slug}/threads/{thread:slug}/subscriptions', [ForumThreadSubscriptionController::class, 'store'])
                ->name('forum.threads.subscribe');
            Route::delete('/forum/boards/{board:slug}/threads/{thread:slug}/subscriptions', [ForumThreadSubscriptionController::class, 'destroy'])
                ->name('forum.threads.unsubscribe');

            Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}', [ForumThreadCommandController::class, 'update'])
                ->name('forum.threads.update');
        });

        Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity', 'throttle:20,1', 'role:admin|editor|moderator'])
            ->group(function () {
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/publish', [ApiForumThreadModerationController::class, 'publish'])
                    ->name('forum.threads.publish');
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/unpublish', [ApiForumThreadModerationController::class, 'unpublish'])
                    ->name('forum.threads.unpublish');
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/lock', [ApiForumThreadModerationController::class, 'lock'])
                    ->name('forum.threads.lock');
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/unlock', [ApiForumThreadModerationController::class, 'unlock'])
                    ->name('forum.threads.unlock');
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/pin', [ApiForumThreadModerationController::class, 'pin'])
                    ->name('forum.threads.pin');
                Route::patch('/forum/boards/{board:slug}/threads/{thread:slug}/unpin', [ApiForumThreadModerationController::class, 'unpin'])
                    ->name('forum.threads.unpin');
                Route::delete('/forum/boards/{board:slug}/threads/{thread:slug}', [ApiForumThreadModerationController::class, 'destroy'])
                    ->name('forum.threads.destroy');
            });
    });

    $supportThrottle = app()->environment('testing') ? 'throttle:1000,1' : 'throttle:20,1';
    $ticketWriteThrottle = app()->environment('testing') ? 'throttle:1000,1' : 'throttle:10,1';
    $ticketReadThrottle = app()->environment('testing') ? 'throttle:1000,1' : 'throttle:30,1';
    $ticketRatingThrottle = app()->environment('testing') ? 'throttle:1000,1' : 'throttle:5,1';

    Route::middleware(['section.enabled:support', 'auth:sanctum', 'token.throttle', 'token.activity', $supportThrottle])
        ->prefix('support')
        ->as('support.')
        ->group(function () use ($ticketWriteThrottle, $ticketReadThrottle, $ticketRatingThrottle) {
            // Support center endpoints: customer ticket creation, threaded messages,
            // status transitions, and post-resolution CSAT ratings. All routes are
            // Sanctum-protected and limited to 20 requests per minute, in addition
            // to the custom token throttle + activity middleware.
            Route::post('/tickets', [SupportTicketController::class, 'store'])
                ->middleware($ticketWriteThrottle)
                ->name('tickets.store');
            Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])
                ->middleware($ticketReadThrottle)
                ->name('tickets.show');
            Route::post('/tickets/{ticket}/messages', [SupportTicketMessageController::class, 'store'])
                ->middleware($ticketWriteThrottle)
                ->name('tickets.messages.store');
            Route::patch('/tickets/{ticket}/status', [SupportTicketStatusController::class, 'update'])
                ->middleware($ticketWriteThrottle)
                ->name('tickets.status.update');
            Route::patch('/tickets/{ticket}/reopen', [SupportTicketStatusController::class, 'reopen'])
                ->middleware($ticketWriteThrottle)
                ->name('tickets.reopen');
            Route::post('/tickets/{ticket}/rating', [SupportTicketRatingController::class, 'store'])
                ->middleware($ticketRatingThrottle)
                ->name('tickets.rating.store');
        });
});

Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity'])
    ->get('/user', UserProfileController::class)
    ->name('api.user.show');
