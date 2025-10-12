<?php

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogCommentSubscriptionController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\ForumPostController;
use App\Http\Controllers\ForumThreadActionController;
use App\Http\Controllers\ForumPostRevisionController;
use App\Http\Controllers\ForumThreadModerationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SearchResultsController;
use App\Http\Controllers\SupportCenterController;
use App\Http\Controllers\UserNotificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::view('/api/docs', 'api.docs')->name('api.docs');
Route::get('/api/docs/openapi.json', ApiDocumentationController::class)
    ->name('api.docs.schema');

//PUBLIC PAGES
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/search', SearchController::class)->name('search');
Route::get('/search/results', SearchResultsController::class)->name('search.results');

// Public Blog Routes
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
Route::get('/blogs/feed', [BlogController::class, 'feed'])->name('blogs.feed');
Route::get('/blogs/preview/{blog}/{token}', [BlogController::class, 'preview'])->name('blogs.preview');
Route::get('/blogs/{slug}', [BlogController::class, 'show'])->name('blogs.view');

Route::prefix('blogs/{blog:slug}/comments')->group(function () {
    Route::get('/', [BlogCommentController::class, 'index'])->name('blogs.comments.index');

    Route::middleware('auth')->group(function () {
        Route::post('/', [BlogCommentController::class, 'store'])->name('blogs.comments.store');
        Route::put('/{comment}', [BlogCommentController::class, 'update'])
            ->whereNumber('comment')
            ->name('blogs.comments.update');
        Route::delete('/{comment}', [BlogCommentController::class, 'destroy'])
            ->whereNumber('comment')
            ->name('blogs.comments.destroy');
        Route::post('/subscriptions', [BlogCommentSubscriptionController::class, 'store'])
            ->name('blogs.comments.subscriptions.store');
        Route::delete('/subscriptions', [BlogCommentSubscriptionController::class, 'destroy'])
            ->name('blogs.comments.subscriptions.destroy');
    });
});

Route::get('forum', [ForumController::class, 'index'])->name('forum.index');
Route::middleware('auth')->get('forum/mentions', [ForumController::class, 'mentionSuggestions'])
    ->name('forum.mentions.index');
Route::get('forum/{board:slug}', [ForumController::class, 'showBoard'])->name('forum.boards.show');
Route::get('forum/{board:slug}/{thread:slug}', [ForumController::class, 'showThread'])->name('forum.threads.show');

Route::middleware('auth')->group(function () {
    Route::post('notifications/read-all', [UserNotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [UserNotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::delete('notifications/{notification}', [UserNotificationController::class, 'destroy'])
        ->name('notifications.destroy');

    Route::get('forum/{board:slug}/threads/create', [ForumController::class, 'createThread'])
        ->name('forum.threads.create');
    Route::post('forum/{board:slug}/threads', [ForumController::class, 'storeThread'])
        ->name('forum.threads.store');
    Route::post('forum/{board:slug}/{thread:slug}/report', [ForumThreadActionController::class, 'report'])
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

    Route::post('forum/{board:slug}/{thread:slug}/posts', [ForumPostController::class, 'store'])
        ->name('forum.posts.store');
    Route::put('forum/{board:slug}/{thread:slug}/posts/{post}', [ForumPostController::class, 'update'])
        ->name('forum.posts.update');
    Route::delete('forum/{board:slug}/{thread:slug}/posts/{post}', [ForumPostController::class, 'destroy'])
        ->name('forum.posts.destroy');
    Route::post('forum/{board:slug}/{thread:slug}/posts/{post}/report', [ForumPostController::class, 'report'])
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

Route::get('support', [SupportCenterController::class, 'index'])->name('support');

Route::middleware('auth')->group(function () {
    Route::post('support/tickets', [SupportCenterController::class, 'store'])
        ->name('support.tickets.store');

    Route::get('support/tickets/{ticket}', [SupportCenterController::class, 'show'])
        ->name('support.tickets.show');

    Route::post('support/tickets/{ticket}/messages', [SupportCenterController::class, 'storeMessage'])
        ->name('support.tickets.messages.store');

    Route::post('support/tickets/{ticket}/rating', [SupportCenterController::class, 'storeRating'])
        ->name('support.tickets.rating.store');

    Route::patch('support/tickets/{ticket}/status', [SupportCenterController::class, 'updateStatus'])
        ->name('support.tickets.status.update');

    Route::patch('support/tickets/{ticket}/reopen', [SupportCenterController::class, 'reopen'])
        ->name('support.tickets.reopen');

    Route::post('support/faqs/{faq}/feedback', [SupportCenterController::class, 'storeFaqFeedback'])
        ->whereNumber('faq')
        ->name('support.faqs.feedback.store');
});

//AUTH REQUIRED PAGES
Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
