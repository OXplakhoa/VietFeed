<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/search', [SearchController::class, 'index'])->name('search');

// ── AJAX API routes (no auth required for search) ──────────────
Route::get('/api/live-search', [SearchController::class, 'liveSearch'])->name('search.live');
Route::get('/api/articles', [HomeController::class, 'loadMore'])->name('api.articles.load');

// ── Auth-required routes ───────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    // Bookmarks
    Route::get('/bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
    Route::post('/bookmarks/toggle', [BookmarkController::class, 'toggle'])->name('bookmarks.toggle');

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
});

require __DIR__.'/auth.php';
