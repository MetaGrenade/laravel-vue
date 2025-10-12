<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\ForumThreadController;
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

    Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
    Route::get('/blogs/{blog:slug}', [BlogController::class, 'show'])->name('blogs.show');

    Route::get('/forum/threads', [ForumThreadController::class, 'index'])
        ->name('forum.threads.index');
    Route::get('/forum/threads/{thread:slug}', [ForumThreadController::class, 'show'])
        ->name('forum.threads.show');
});

Route::middleware(['auth:sanctum', 'token.throttle', 'token.activity'])
    ->get('/user', UserProfileController::class)
    ->name('api.user.show');
