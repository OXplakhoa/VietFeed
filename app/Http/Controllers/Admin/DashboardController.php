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

        // Articles per day — last 30 days (Gate 3 spillover: DATE() is SQL-only;
        // range query on Mongo + bucket in PHP yields identical numbers).
        $dayStart = now()->subDays(29)->startOfDay();
        $articlesPerDayRaw = Article::where('created_at', '>=', $dayStart)->pluck('created_at')
            ->map(fn ($date) => $date->format('Y-m-d'))->countBy();

        // Users per day — last 30 days (same spillover).
        $usersPerDayRaw = User::where('created_at', '>=', $dayStart)->pluck('created_at')
            ->map(fn ($date) => $date->format('Y-m-d'))->countBy();

        $chartDates = [];
        $chartArticles = [];
        $chartUsers = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $chartDates[] = now()->subDays($i)->format('d/m');
            $chartArticles[] = (int) ($articlesPerDayRaw->get($date, 0));
            $chartUsers[] = (int) ($usersPerDayRaw->get($date, 0));
        }

        // Gate 3: articles live on Mongo — cross-store withCount can't join, so count
        // on each store natively and stitch. Same view attributes, identical numbers.
        $articlesByCategory = collect(iterator_to_array(Article::raw(fn ($collection) => $collection->aggregate([
            ['$group' => ['_id' => '$category_id', 'n' => ['$sum' => 1]]],
        ]))))->pluck('n', 'id');
        $perCategory = Category::get()->each(fn ($category) => $category->articles_count = (int) ($articlesByCategory[$category->id] ?? 0));
        $articlesBySource = collect(iterator_to_array(Article::raw(fn ($collection) => $collection->aggregate([
            ['$group' => ['_id' => '$source_id', 'n' => ['$sum' => 1]]],
        ]))))->pluck('n', 'id');
        $perSource = Source::get()
            ->each(fn ($source) => $source->articles_count = (int) ($articlesBySource[$source->id] ?? 0))
            ->sortByDesc('articles_count')->take(10)->values();
        $bookmarksByArticle = Bookmark::select('article_id')->selectRaw('COUNT(*) as n')
            ->groupBy('article_id')->orderByDesc('n')->take(10)->pluck('n', 'article_id');
        $mostBookmarked = Article::whereIn('_id', $bookmarksByArticle->keys())->get()
            ->each(fn ($article) => $article->bookmarks_count = (int) $bookmarksByArticle[$article->getKey()])
            ->sortByDesc('bookmarks_count')->values();
        // pluck('id'): the package maps storage _id to the id attribute on read.
        $recentIds = Article::where('published_at', '>=', now()->subHours(72))->pluck('id');
        $boostsByArticle = Boost::select('article_id')->selectRaw('COUNT(*) as n')
            ->whereIn('article_id', $recentIds)->groupBy('article_id')->orderByDesc('n')->take(10)->pluck('n', 'article_id');
        $mostBoosted = Article::whereIn('_id', $boostsByArticle->keys())->get()
            ->each(fn ($article) => $article->boosts_count = (int) $boostsByArticle[$article->getKey()])
            ->sortBy([['boosts_count', 'desc'], ['published_at', 'desc']])->values();

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
