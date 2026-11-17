<?php

namespace App\Http\Controllers;

use App\Content\ArticleCounts;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function show(string $slug)
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $articles = Article::where('category_id', $category->id)
            ->whereIn('source_id', Source::active()->pluck('id'))
            ->with(['source', 'category'])
            ->latest('published_at')
            ->paginate(12);
        ArticleCounts::attach($articles->getCollection());

        /** @var User|null $user */
        $user = Auth::user();

        $bookmarkedIds = $user
            ? $user->bookmarks()->pluck('article_id')->toArray()
            : [];

        $boostedIds = $user
            ? $user->boosts()->pluck('article_id')->toArray()
            : [];

        $categories = Category::active()->get();

        return view('categories.show', compact('category', 'articles', 'bookmarkedIds', 'boostedIds', 'categories'));
    }
}
