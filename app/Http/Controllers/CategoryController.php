<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function show(string $slug)
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $articles = Article::where('category_id', $category->id)
            ->whereHas('source', fn ($qb) => $qb->active())
            ->with(['source', 'category'])
            ->withCount(['bookmarks', 'boosts'])
            ->latest('published_at')
            ->paginate(12);

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
