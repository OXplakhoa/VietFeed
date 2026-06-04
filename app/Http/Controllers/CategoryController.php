<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function show(string $slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $articles = Article::where('category_id', $category->id)
            ->with(['source', 'category'])
            ->withCount(['bookmarks', 'boosts'])
            ->latest('published_at')
            ->paginate(12);

        $bookmarkedIds = Auth::check()
            ? Auth::user()->bookmarks()->pluck('article_id')->toArray()
            : [];

        $boostedIds = Auth::check()
            ? Auth::user()->boosts()->pluck('article_id')->toArray()
            : [];

        $categories = Category::all();

        return view('categories.show', compact('category', 'articles', 'bookmarkedIds', 'boostedIds', 'categories'));
    }
}
