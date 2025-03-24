<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//PUBLIC PAGES
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('blog', function () {
    return Inertia::render('Blog');
})->name('blog');

Route::get('forum', function () {
    return Inertia::render('Forum');
})->name('forum');

//AUTH REQUIRED PAGES
Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
