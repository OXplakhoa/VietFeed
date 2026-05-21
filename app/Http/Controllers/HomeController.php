<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

class HomeController extends Controller
{
    public function index()
    {
        $featured = Article::whereNotNull('image_url')->latest('published_at')->first();

        $heroIds = $featured ? [$featured->id] : [];
        $heroArticles = Article::whereNotNull('image_url')
            ->whereNotIn('id', $heroIds)
            ->latest('published_at')
            ->take(3)
            ->get();

        $query = Article::with(['source', 'category'])->withCount('bookmarks');

        if (auth()->check() && auth()->user()->favoriteCategories()->count() > 0) {
            $catIds = auth()->user()->favoriteCategories()->pluck('categories.id');
            $query->whereIn('category_id', $catIds);
        }

        $articles = $query->latest('published_at')->paginate(12);

        $trending = Article::withCount('bookmarks')
            ->orderByDesc('bookmarks_count')
            ->latest('published_at')
            ->take(5)
            ->get();

        $bookmarkedIds = auth()->check()
            ? auth()->user()->bookmarks()->pluck('article_id')->toArray()
            : [];

        $categories = Category::all();

        return view('home.index', compact(
            'featured', 'heroArticles', 'articles', 'trending', 'bookmarkedIds', 'categories'
        ));
    }

    public function loadMore(Request $request)
    {
        $page = max(1, (int) $request->get('page', 2));

        $query = Article::with(['source', 'category'])->withCount('bookmarks');

        if (auth()->check() && auth()->user()->favoriteCategories()->count() > 0) {
            $catIds = auth()->user()->favoriteCategories()->pluck('categories.id');
            $query->whereIn('category_id', $catIds);
        }

        $articles = $query->latest('published_at')->paginate(12, ['*'], 'page', $page);

        $html = '';
        foreach ($articles->items() as $article) {
            $html .= '<div class="col-sm-6 col-lg-4 mb-4">'
                . Blade::render(
                    '<x-article-card :article="$article" :bookmarked="$bm" />',
                    ['article' => $article, 'bm' => false]
                )
                . '</div>';
        }

        return response()->json([
            'html'     => $html,
            'hasMore'  => $articles->hasMorePages(),
            'nextPage' => $page + 1,
        ]);
    }
}
