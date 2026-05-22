<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Source;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'articles'  => Article::count(),
            'users'     => User::count(),
            'comments'  => Comment::count(),
            'sources'   => Source::where('is_active', true)->count(),
            'bookmarks' => Bookmark::count(),
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

        $chartDates    = [];
        $chartArticles = [];
        $chartUsers    = [];

        for ($i = 29; $i >= 0; $i--) {
            $date           = now()->subDays($i)->format('Y-m-d');
            $chartDates[]   = now()->subDays($i)->format('d/m');
            $chartArticles[] = (int) ($articlesPerDayRaw->get($date, 0));
            $chartUsers[]   = (int) ($usersPerDayRaw->get($date, 0));
        }

        $perCategory    = Category::withCount('articles')->get();
        $perSource      = Source::withCount('articles')->orderByDesc('articles_count')->take(10)->get();
        $mostBookmarked = Article::withCount('bookmarks')->orderByDesc('bookmarks_count')->take(10)->get();

        $recentArticles = Article::with('source')->latest()->take(8)->get();
        $recentComments = Comment::with(['user', 'article'])->latest()->take(6)->get();
        $sources        = Source::with('category')->orderByDesc('last_fetched_at')->get();

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
            'mostBookmarked'
        ));
    }
}
