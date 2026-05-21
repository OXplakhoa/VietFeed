<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Comment;
use App\Models\Source;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'articles' => Article::count(),
            'users'    => User::count(),
            'comments' => Comment::count(),
            'sources'  => Source::where('is_active', true)->count(),
        ];

        $recentArticles = Article::with('source')->latest()->take(8)->get();
        $sources        = Source::with('category')->orderByDesc('last_fetched_at')->get();

        return view('admin.dashboard', compact('stats', 'recentArticles', 'sources'));
    }
}
