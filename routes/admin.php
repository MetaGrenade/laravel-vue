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
});
