<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\ForumController;
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
