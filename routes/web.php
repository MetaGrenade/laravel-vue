<?php

use App\Http\Controllers\BlogController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//PUBLIC PAGES
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Public Blog Routes
Route::get('/blogs', [BlogController::class, 'index'])->name('blogs.index');
Route::get('/blogs/{slug}', [BlogController::class, 'show'])->name('blogs.view');

Route::get('forum', function () {
    return Inertia::render('Forum');
})->name('forum');

Route::get('forum/threads', function () {
    return Inertia::render('ForumThreads');
})->name('forum.threads');

Route::get('forum/threads/view', function () {
    return Inertia::render('ForumThreadView');
})->name('forum.thread.view');

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
