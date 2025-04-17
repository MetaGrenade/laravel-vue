<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'role:admin|editor|moderator'])->group(function () {
    Route::redirect('acp', '/acp/dashboard');

//    Route::get('acp/dashboard', [AdminController::class, 'get'])->name('acp.dashboard');

    Route::get('acp/dashboard', function () {
        return Inertia::render('acp/Dashboard');
    })->name('acp.dashboard');

    Route::get('acp/users', function () {
        return Inertia::render('acp/Users');
    })->name('acp.users');

    Route::get('acp/permissions', function () {
        return Inertia::render('acp/Permissions');
    })->name('acp.permissions');

    // Admin Blog Management Routes
    Route::get('/acp/blogs', [AdminBlogController::class, 'index'])->name('acp.blogs.index');
    Route::get('/acp/blogs/create', [AdminBlogController::class, 'create'])->name('acp.blogs.create');
    Route::post('/acp/blogs', [AdminBlogController::class, 'store'])->name('acp.blogs.store');
    Route::get('/acp/blogs/{blog}/edit', [AdminBlogController::class, 'edit'])->name('acp.blogs.edit');
    Route::put('/acp/blogs/{blog}', [AdminBlogController::class, 'update'])->name('acp.blogs.update');
    Route::delete('/acp/blogs/{blog}', [AdminBlogController::class, 'destroy'])->name('acp.blogs.destroy');

    Route::get('acp/forums', function () {
        return Inertia::render('acp/Forums');
    })->name('acp.forums');

    Route::get('acp/support', function () {
        return Inertia::render('acp/Support');
    })->name('acp.support');

    Route::get('acp/system', function () {
        return Inertia::render('acp/System');
    })->name('acp.system');

    Route::get('acp/tokens', function () {
        return Inertia::render('acp/Tokens');
    })->name('acp.tokens');

    Route::get('acp/tokens/logs/view', function () {
        return Inertia::render('acp/TokenLogView');
    })->name('acp.tokens.logs.view');
});
