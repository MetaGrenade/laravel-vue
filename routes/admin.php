<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BlogTagController;
use App\Http\Controllers\Admin\ACLController as AdminACLController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SupportTicketCategoryController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\UsersController as AdminUserController;
use App\Http\Controllers\Admin\ForumBoardController;
use App\Http\Controllers\Admin\ForumCategoryController;
use App\Http\Controllers\Admin\ForumReportController;
use App\Http\Controllers\Admin\FaqCategoryController;
use App\Http\Controllers\Admin\BadgeController;
use App\Http\Controllers\Admin\BillingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'role:admin|editor|moderator'])->group(function () {
    Route::redirect('acp', '/acp/dashboard');

    Route::get('acp/dashboard', [AdminController::class, 'get'])->name('acp.dashboard');

    // Admin User Management Routes
    Route::get('acp/users', [AdminUserController::class, 'index'])->name('acp.users.index');
    Route::get('acp/users/{user}/edit', [AdminUserController::class, 'edit'])->name('acp.users.edit');
    Route::put('acp/users/{user}', [AdminUserController::class, 'update'])->name('acp.users.update');
    Route::delete('acp/users/{user}', [AdminUserController::class, 'destroy'])->name('acp.users.destroy');
    Route::put('acp/users/{user}/verify', [AdminUserController::class, 'verify'])->name('acp.users.verify');
    Route::put('acp/users/{user}/ban', [AdminUserController::class, 'ban'])->name('acp.users.ban');
    Route::put('acp/users/{user}/unban', [AdminUserController::class, 'unban'])->name('acp.users.unban');
    Route::patch('acp/users/bulk', [AdminUserController::class, 'bulkUpdate'])->name('acp.users.bulk-update');

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
    Route::put('acp/blogs/{blog}/archive', [AdminBlogController::class, 'archive'])->name('acp.blogs.archive');
    Route::put('acp/blogs/{blog}/unarchive', [AdminBlogController::class, 'unarchive'])->name('acp.blogs.unarchive');
    Route::patch('acp/blogs/bulk/status', [AdminBlogController::class, 'bulkUpdateStatus'])->name('acp.blogs.bulk-status');

    // Admin Blog Tag Management Routes
    Route::get('acp/blog-tags', [BlogTagController::class, 'index'])->name('acp.blog-tags.index');
    Route::get('acp/blog-tags/create', [BlogTagController::class, 'create'])->name('acp.blog-tags.create');
    Route::post('acp/blog-tags', [BlogTagController::class, 'store'])->name('acp.blog-tags.store');
    Route::get('acp/blog-tags/{tag}/edit', [BlogTagController::class, 'edit'])->name('acp.blog-tags.edit');
    Route::put('acp/blog-tags/{tag}', [BlogTagController::class, 'update'])->name('acp.blog-tags.update');
    Route::delete('acp/blog-tags/{tag}', [BlogTagController::class, 'destroy'])->name('acp.blog-tags.destroy');

    // Admin Blog Category Management Routes
    Route::get('acp/blog-categories', [BlogCategoryController::class, 'index'])->name('acp.blog-categories.index');
    Route::get('acp/blog-categories/create', [BlogCategoryController::class, 'create'])->name('acp.blog-categories.create');
    Route::post('acp/blog-categories', [BlogCategoryController::class, 'store'])->name('acp.blog-categories.store');
    Route::get('acp/blog-categories/{category}/edit', [BlogCategoryController::class, 'edit'])->name('acp.blog-categories.edit');
    Route::put('acp/blog-categories/{category}', [BlogCategoryController::class, 'update'])->name('acp.blog-categories.update');
    Route::delete('acp/blog-categories/{category}', [BlogCategoryController::class, 'destroy'])->name('acp.blog-categories.destroy');

    Route::get('acp/forums', [ForumCategoryController::class, 'index'])->name('acp.forums.index');
    Route::get('acp/forums/reports', [ForumReportController::class, 'index'])->name('acp.forums.reports.index');
    Route::patch('acp/forums/reports/bulk/status', [ForumReportController::class, 'bulkUpdateStatus'])->name('acp.forums.reports.bulk-status');
    Route::patch('acp/forums/reports/threads/{report}', [ForumReportController::class, 'updateThread'])->name('acp.forums.reports.threads.update');
    Route::patch('acp/forums/reports/posts/{report}', [ForumReportController::class, 'updatePost'])->name('acp.forums.reports.posts.update');
    Route::get('acp/forums/categories/create', [ForumCategoryController::class, 'create'])->name('acp.forums.categories.create');
    Route::post('acp/forums/categories', [ForumCategoryController::class, 'store'])->name('acp.forums.categories.store');
    Route::get('acp/forums/categories/{category}/edit', [ForumCategoryController::class, 'edit'])->name('acp.forums.categories.edit');
    Route::put('acp/forums/categories/{category}', [ForumCategoryController::class, 'update'])->name('acp.forums.categories.update');
    Route::delete('acp/forums/categories/{category}', [ForumCategoryController::class, 'destroy'])->name('acp.forums.categories.destroy');
    Route::patch('acp/forums/categories/{category}/reorder', [ForumCategoryController::class, 'reorder'])->name('acp.forums.categories.reorder');

    Route::get('acp/forums/boards/create', [ForumBoardController::class, 'create'])->name('acp.forums.boards.create');
    Route::post('acp/forums/boards', [ForumBoardController::class, 'store'])->name('acp.forums.boards.store');
    Route::get('acp/forums/boards/{board:id}/edit', [ForumBoardController::class, 'edit'])->name('acp.forums.boards.edit');
    Route::put('acp/forums/boards/{board:id}', [ForumBoardController::class, 'update'])->name('acp.forums.boards.update');
    Route::delete('acp/forums/boards/{board:id}', [ForumBoardController::class, 'destroy'])->name('acp.forums.boards.destroy');
    Route::patch('acp/forums/boards/{board:id}/reorder', [ForumBoardController::class, 'reorder'])->name('acp.forums.boards.reorder');

    // Support ACP
    Route::get('acp/support', [SupportController::class,'index'])->name('acp.support.index');
    Route::get('acp/support/users/search', [SupportController::class,'searchUsers'])->name('acp.support.users.search');

    Route::get('acp/billing/invoices', [BillingController::class, 'invoices'])->name('acp.billing.invoices.index');

    Route::get('acp/support/templates', [SupportController::class, 'templates'])->name('acp.support.templates.index');
    Route::post('acp/support/templates', [SupportController::class, 'storeTemplate'])->name('acp.support.templates.store');
    Route::put('acp/support/templates/{template}', [SupportController::class, 'updateTemplate'])->name('acp.support.templates.update');
    Route::delete('acp/support/templates/{template}', [SupportController::class, 'destroyTemplate'])->name('acp.support.templates.destroy');

    Route::get('acp/support/teams', [SupportController::class, 'teams'])->name('acp.support.teams.index');
    Route::post('acp/support/teams', [SupportController::class, 'storeTeam'])->name('acp.support.teams.store');
    Route::put('acp/support/teams/{team}', [SupportController::class, 'updateTeam'])->name('acp.support.teams.update');
    Route::delete('acp/support/teams/{team}', [SupportController::class, 'destroyTeam'])->name('acp.support.teams.destroy');

    // Tickets
    Route::get('acp/support/tickets/create', [SupportController::class,'createTicket'])->name('acp.support.tickets.create');
    Route::get('acp/support/tickets/{ticket}', [SupportController::class,'showTicket'])->name('acp.support.tickets.show');
    Route::get('acp/support/tickets/{ticket}/edit', [SupportController::class,'editTicket'])->name('acp.support.tickets.edit');
    Route::post('acp/support/tickets', [SupportController::class,'storeTicket'])->name('acp.support.tickets.store');
    Route::post('acp/support/tickets/{ticket}/messages', [SupportController::class,'storeTicketMessage'])->name('acp.support.tickets.messages.store');
    Route::put('acp/support/tickets/{ticket}', [SupportController::class,'updateTicket'])->name('acp.support.tickets.update');
    Route::delete('acp/support/tickets/{ticket}', [SupportController::class,'destroyTicket'])->name('acp.support.tickets.destroy');
    Route::put('acp/support/tickets/{ticket}/assign', [SupportController::class,'assignTicket'])->name('acp.support.tickets.assign');
    Route::put('acp/support/tickets/{ticket}/priority', [SupportController::class,'updateTicketPriority'])->name('acp.support.tickets.priority');
    Route::put('acp/support/tickets/{ticket}/status', [SupportController::class,'updateTicketStatus'])->name('acp.support.tickets.status');
    Route::patch('acp/support/tickets/bulk/status', [SupportController::class,'bulkUpdateStatus'])->name('acp.support.tickets.bulk-status');

    // Ticket categories
    Route::get('acp/support/ticket-categories', [SupportTicketCategoryController::class, 'index'])->name('acp.support.ticket-categories.index');
    Route::get('acp/support/ticket-categories/create', [SupportTicketCategoryController::class, 'create'])->name('acp.support.ticket-categories.create');
    Route::post('acp/support/ticket-categories', [SupportTicketCategoryController::class, 'store'])->name('acp.support.ticket-categories.store');
    Route::get('acp/support/ticket-categories/{category}/edit', [SupportTicketCategoryController::class, 'edit'])->name('acp.support.ticket-categories.edit');
    Route::put('acp/support/ticket-categories/{category}', [SupportTicketCategoryController::class, 'update'])->name('acp.support.ticket-categories.update');
    Route::delete('acp/support/ticket-categories/{category}', [SupportTicketCategoryController::class, 'destroy'])->name('acp.support.ticket-categories.destroy');

    // FAQs
    Route::get('acp/support/faqs/create', [SupportController::class,'createFaq'])->name('acp.support.faqs.create');
    Route::get('acp/support/faqs/{faq}/edit', [SupportController::class,'editFaq'])->name('acp.support.faqs.edit');
    Route::post('acp/support/faqs', [SupportController::class,'storeFaq'])->name('acp.support.faqs.store');
    Route::put('acp/support/faqs/{faq}', [SupportController::class,'updateFaq'])->name('acp.support.faqs.update');
    Route::delete('acp/support/faqs/{faq}', [SupportController::class,'destroyFaq'])->name('acp.support.faqs.destroy');
    Route::patch('acp/support/faqs/{faq}/reorder', [SupportController::class,'reorderFaq'])->name('acp.support.faqs.reorder');
    Route::patch('acp/support/faqs/{faq}/publish', [SupportController::class,'publishFaq'])->name('acp.support.faqs.publish');
    Route::patch('acp/support/faqs/{faq}/unpublish', [SupportController::class,'unpublishFaq'])->name('acp.support.faqs.unpublish');

    // FAQ Categories
    Route::get('acp/support/faq-categories', [FaqCategoryController::class, 'index'])->name('acp.support.faq-categories.index');
    Route::get('acp/support/faq-categories/create', [FaqCategoryController::class, 'create'])->name('acp.support.faq-categories.create');
    Route::post('acp/support/faq-categories', [FaqCategoryController::class, 'store'])->name('acp.support.faq-categories.store');
    Route::get('acp/support/faq-categories/{category}/edit', [FaqCategoryController::class, 'edit'])->name('acp.support.faq-categories.edit');
    Route::put('acp/support/faq-categories/{category}', [FaqCategoryController::class, 'update'])->name('acp.support.faq-categories.update');
    Route::delete('acp/support/faq-categories/{category}', [FaqCategoryController::class, 'destroy'])->name('acp.support.faq-categories.destroy');

    Route::get('acp/system', [SystemSettingsController::class, 'index'])->name('acp.system');
    Route::put('acp/system', [SystemSettingsController::class, 'update'])->name('acp.system.update');

    Route::get('acp/reputation/badges', [BadgeController::class, 'index'])->name('acp.reputation.badges.index');
    Route::get('acp/reputation/badges/create', [BadgeController::class, 'create'])->name('acp.reputation.badges.create');
    Route::post('acp/reputation/badges', [BadgeController::class, 'store'])->name('acp.reputation.badges.store');
    Route::get('acp/reputation/badges/{badge}/edit', [BadgeController::class, 'edit'])->name('acp.reputation.badges.edit');
    Route::put('acp/reputation/badges/{badge}', [BadgeController::class, 'update'])->name('acp.reputation.badges.update');
    Route::delete('acp/reputation/badges/{badge}', [BadgeController::class, 'destroy'])->name('acp.reputation.badges.destroy');

    // Tokens
    Route::get('acp/tokens', [TokenController::class,'index'])->name('acp.tokens.index');
    Route::post('acp/tokens', [TokenController::class,'store'])->name('acp.tokens.store');
    Route::put('acp/tokens/{token}', [TokenController::class,'update'])->name('acp.tokens.update');
    Route::patch('acp/tokens/{token}/revoke', [TokenController::class,'revoke'])->name('acp.tokens.revoke');
    Route::delete('acp/tokens/{token}', [TokenController::class,'destroy'])->name('acp.tokens.destroy');

    Route::get('acp/tokens/logs/{tokenLog}', [TokenController::class, 'showLog'])
        ->name('acp.tokens.logs.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('acp/blogs/{blog}/revisions', [AdminBlogController::class, 'revisions'])
        ->name('acp.blogs.revisions.index');
    Route::put('acp/blogs/{blog}/revisions/{revision}', [AdminBlogController::class, 'restoreRevision'])
        ->name('acp.blogs.revisions.restore');
});
