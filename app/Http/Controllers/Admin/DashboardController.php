<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Source;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $allSources = Source::with('category')->orderByDesc('last_fetched_at')->get();
        $healthCounts = collect(['healthy', 'warning', 'failed', 'stale', 'critical', 'disabled', 'never_fetched'])
            ->mapWithKeys(fn ($status) => [$status => $allSources->where('health_status', $status)->count()]);

        $stats = [
            'articles' => Article::count(),
            'users' => User::count(),
            'comments' => Comment::count(),
            'sources' => Source::where('is_active', true)->count(),
            'bookmarks' => Bookmark::count(),
            'boosts' => Boost::count(),
            'unlocks_today' => ArticleUnlock::where('unlocked_at', '>=', now('Asia/Ho_Chi_Minh')->startOfDay())->count(),
            'unlocks_5h' => ArticleUnlock::where('unlocked_at', '>=', now()->subHours(5))->count(),
            'pro_users' => User::whereHas('subscriptions', fn ($query) => $query
                ->where('type', 'pro')
                ->whereIn('stripe_status', ['active', 'trialing'])
            )->count(),
        ];

        // Articles per day — last 30 days
        $articlesPerDayRaw = Article::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        // Users per day — last 30 days
        $usersPerDayRaw = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        $chartDates = [];
        $chartArticles = [];
        $chartUsers = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $chartDates[] = now()->subDays($i)->format('d/m');
            $chartArticles[] = (int) ($articlesPerDayRaw->get($date, 0));
            $chartUsers[] = (int) ($usersPerDayRaw->get($date, 0));
        }

        $perCategory = Category::withCount('articles')->get();
        $perSource = Source::withCount('articles')->orderByDesc('articles_count')->take(10)->get();
        $mostBookmarked = Article::withCount('bookmarks')->orderByDesc('bookmarks_count')->take(10)->get();
        $mostBoosted = Article::withCount('boosts')
            ->where('published_at', '>=', now()->subHours(72))
            ->orderByDesc('boosts_count')
            ->latest('published_at')
            ->take(10)
            ->get();

        $recentArticles = Article::with('source')->latest()->take(8)->get();
        $recentComments = Comment::with(['user', 'article'])->latest()->take(6)->get();
        $sources = $allSources;
        $problemSources = $allSources
            ->filter(fn (Source $source) => in_array($source->health_status, ['warning', 'failed', 'stale', 'critical'], true))
            ->sortByDesc(function (Source $source) {
                $severity = match ($source->health_status) {
                    'critical' => 4000000,
                    'failed' => 3000000,
                    'stale' => 2000000,
                    'warning' => 1000000,
                    default => 0,
                };

                return $severity + (($source->consecutive_failures ?? 0) * 1000) + (optional($source->last_failed_fetch_at)->timestamp ?? 0);
            })
            ->take(5)
            ->values();

        return view('admin.dashboard', compact(
            'stats',
            'recentArticles',
            'recentComments',
            'sources',
            'chartDates',
            'chartArticles',
            'chartUsers',
            'perCategory',
            'perSource',
            'mostBookmarked',
            'mostBoosted',
            'healthCounts',
            'problemSources'
        ));
    }
}
