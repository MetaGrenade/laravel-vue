<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\ACLController as AdminACLController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\UsersController as AdminUserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'role:admin|editor|moderator'])->group(function () {
    Route::redirect('acp', '/acp/dashboard');

    Route::get('acp/dashboard', function () {
        return Inertia::render('acp/Dashboard');
    })->name('acp.dashboard');

    Route::get('acp/users', function () {
        return Inertia::render('acp/Users');
    })->name('acp.users');

    // Admin User Management Routes
    Route::get('acp/users', [AdminUserController::class, 'index'])->name('acp.users.index');
    Route::get('acp/users/{user}/edit', [AdminUserController::class, 'edit'])->name('acp.users.edit');
    Route::put('acp/users/{user}', [AdminUserController::class, 'update'])->name('acp.users.update');
    Route::delete('acp/users/{user}', [AdminUserController::class, 'destroy'])->name('acp.users.destroy');
    Route::put('acp/users/{user}/verify', [AdminUserController::class, 'verify'])->name('acp.users.verify');

    // Admin Access Control Management Routes
    Route::get('acp/acl', [AdminACLController::class, 'index'])->name('acp.acl.index');
    Route::get('acp/acl/permissions/create', [AdminACLController::class, 'createPermission'])->name('acp.acl.permissions.create');
    Route::post('acp/acl/permissions', [AdminACLController::class, 'storePermission'])->name('acp.acl.permissions.store');
    Route::put('acp/acl/permissions/{permission}', [AdminACLController::class, 'updatePermission'])->name('acp.acl.permissions.update');
    Route::delete('acp/acl/permissions/{permission}', [AdminACLController::class, 'destroyPermission'])->name('acp.acl.permissions.destroy');
    Route::post('acp/acl/roles', [AdminACLController::class, 'storeRole'])->name('acp.acl.roles.store');
    Route::get('acp/acl/roles/create', [AdminACLController::class, 'createRole'])->name('acp.acl.roles.create');
    Route::put('acp/acl/roles/{role}', [AdminACLController::class, 'updateRole'])->name('acp.acl.roles.update');
    Route::delete('acp/acl/roles/{role}', [AdminACLController::class, 'destroyRole'])->name('acp.acl.roles.destroy');

    // Admin Blog Management Routes
    Route::get('acp/blogs', [AdminBlogController::class, 'index'])->name('acp.blogs.index');
    Route::get('acp/blogs/create', [AdminBlogController::class, 'create'])->name('acp.blogs.create');
    Route::post('acp/blogs', [AdminBlogController::class, 'store'])->name('acp.blogs.store');
    Route::get('acp/blogs/{blog}/edit', [AdminBlogController::class, 'edit'])->name('acp.blogs.edit');
    Route::put('acp/blogs/{blog}', [AdminBlogController::class, 'update'])->name('acp.blogs.update');
    Route::delete('acp/blogs/{blog}', [AdminBlogController::class, 'destroy'])->name('acp.blogs.destroy');
    Route::put('acp/blogs/{blog}/publish', [AdminBlogController::class, 'publish'])->name('acp.blogs.publish');
    Route::put('acp/blogs/{blog}/unpublish', [AdminBlogController::class, 'unpublish'])->name('acp.blogs.unpublish');

    Route::get('acp/forums', function () {
        return Inertia::render('acp/Forums');
    })->name('acp.forums');

    Route::get('acp/support', function () {
        return Inertia::render('acp/Support');
    })->name('acp.support');

    // Support ACP
    Route::get('acp/support', [SupportController::class,'index'])->name('acp.support.index');

    // Tickets
    Route::get('acp/support/tickets/create', [SupportController::class,'createTicket'])->name('acp.support.tickets.create');
    Route::post('acp/support/tickets', [SupportController::class,'storeTicket'])->name('acp.support.tickets.store');
    Route::put('acp/support/tickets/{ticket}', [SupportController::class,'updateTicket'])->name('acp.support.tickets.update');
    Route::delete('acp/support/tickets/{ticket}', [SupportController::class,'destroyTicket'])->name('acp.support.tickets.destroy');

    // FAQs
    Route::get('acp/support/faqs/create', [SupportController::class,'createFaq'])->name('acp.support.faqs.create');
    Route::post('acp/support/faqs', [SupportController::class,'storeFaq'])->name('acp.support.faqs.store');
    Route::put('acp/support/faqs/{faq}', [SupportController::class,'updateFaq'])->name('acp.support.faqs.update');
    Route::delete('acp/support/faqs/{faq}', [SupportController::class,'destroyFaq'])->name('acp.support.faqs.destroy');

    Route::get('acp/system', function () {
        return Inertia::render('acp/System');
    })->name('acp.system');

    // Tokens
    Route::get('acp/tokens', [TokenController::class,'index'])->name('acp.tokens.index');
    Route::post('acp/tokens', [TokenController::class,'store'])->name('acp.tokens.store');
    Route::delete('acp/tokens/{token}', [TokenController::class,'destroy'])->name('acp.tokens.destroy');

    Route::get('acp/tokens/logs/view', function () {
        return Inertia::render('acp/TokenLogView');
    })->name('acp.tokens.logs.view');
});
