<?php

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
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

    Route::get('acp/blogs', function () {
        return Inertia::render('acp/Blogs');
    })->name('acp.blogs');

    Route::get('acp/forums', function () {
        return Inertia::render('acp/Forums');
    })->name('acp.forums');

    Route::get('acp/support', function () {
        return Inertia::render('acp/Support');
    })->name('acp.support');

    Route::get('acp/system', function () {
        return Inertia::render('acp/System');
    })->name('acp.system');
});
