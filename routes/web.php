<?php

use App\Http\Controllers\Admin\ArticleController as AdminArticle;
use App\Http\Controllers\Admin\CategoryController as AdminCategory;
use App\Http\Controllers\Admin\CommentController as AdminComment;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\SourceController as AdminSource;
use App\Http\Controllers\Admin\UserController as AdminUser;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\BoostController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TickerController;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/pricing', [BillingController::class, 'pricing'])->name('pricing');

// ── AJAX API routes (no auth required for search) ──────────────
Route::get('/api/live-search', [SearchController::class, 'liveSearch'])->name('search.live');
Route::get('/api/articles', [HomeController::class, 'loadMore'])->name('api.articles.load');
Route::get('/api/ticker', [TickerController::class, 'index'])->name('api.ticker');

// ── Auth-only (controller handles its own verified check for friendlier UX) ──
Route::middleware('auth')->group(function () {
    Route::post('/bookmarks/toggle', [BookmarkController::class, 'toggle'])->name('bookmarks.toggle');
    Route::post('/boosts/toggle', [BoostController::class, 'toggle'])->name('boosts.toggle');
    Route::get('/profile/reading-pass', [ProfileController::class, 'readingPass'])->name('profile.reading-pass');
});

Route::get('/dashboard', function () {
    $user = auth()->user();

    if ($user?->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('home');
})->middleware('auth')->name('dashboard');

// ── Auth-required routes ───────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    // Billing
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');

    // Bookmarks
    Route::get('/bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');

    // Comments (note: {article:slug} for route-model binding by slug)
    Route::post('/articles/{article:slug}/comments', [CommentController::class, 'store'])->name('comments.store');
    Route::put('/comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    // Onboarding
    Route::get('/onboarding/interests', [OnboardingController::class, 'showInterests'])->name('onboarding.interests');
    Route::post('/onboarding/interests', [OnboardingController::class, 'saveInterests'])->name('onboarding.interests.save');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
});

// ── Admin routes ───────────────────────────────────────────────
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminDashboard::class, 'index'])->name('dashboard');

    // Bulk-destroy must be before resource routes so 'bulk-destroy' isn't mistaken for an article ID
    Route::post('articles/bulk-destroy', [AdminArticle::class, 'bulkDestroy'])->name('articles.bulk-destroy');

    Route::get('sources/health', [AdminSource::class, 'health'])->name('sources.health');
    Route::post('sources/{source}/test', [AdminSource::class, 'test'])->name('sources.test');
    Route::post('sources/{source}/retry', [AdminSource::class, 'retry'])->name('sources.retry');
    Route::patch('sources/{source}/toggle-active', [AdminSource::class, 'toggleActive'])->name('sources.toggle-active');

    Route::resource('sources', AdminSource::class);
    Route::resource('categories', AdminCategory::class);
    Route::resource('articles', AdminArticle::class)->except(['create', 'store']);
    Route::resource('comments', AdminComment::class)->only(['index', 'destroy']);
    Route::resource('users', AdminUser::class)->only(['index', 'edit', 'update', 'destroy']);
});

require __DIR__.'/auth.php';
