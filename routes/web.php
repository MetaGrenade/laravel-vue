<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\ForumPostController;
use App\Http\Controllers\ForumThreadActionController;
use App\Http\Controllers\ForumThreadModerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//PUBLIC PAGES
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Public Blog Routes
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
Route::get('/blogs/{slug}', [BlogController::class, 'show'])->name('blogs.view');

Route::get('forum', [ForumController::class, 'index'])->name('forum.index');
Route::get('forum/{board:slug}', [ForumController::class, 'showBoard'])->name('forum.boards.show');
Route::get('forum/{board:slug}/{thread:slug}', [ForumController::class, 'showThread'])->name('forum.threads.show');

Route::middleware('auth')->group(function () {
    Route::get('forum/{board:slug}/threads/create', [ForumController::class, 'createThread'])
        ->name('forum.threads.create');
    Route::post('forum/{board:slug}/threads', [ForumController::class, 'storeThread'])
        ->name('forum.threads.store');
    Route::post('forum/{board:slug}/{thread:slug}/report', [ForumThreadActionController::class, 'report'])
        ->name('forum.threads.report');
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

Route::get('support', function () {
    return Inertia::render('Support');
})->name('support');

//AUTH REQUIRED PAGES
Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
