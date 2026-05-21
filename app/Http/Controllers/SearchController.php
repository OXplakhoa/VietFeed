<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q        = trim($request->get('q', ''));
        $articles = collect();

        if (strlen($q) >= 2) {
            $articles = Article::with(['source', 'category'])
                ->where('title', 'LIKE', "%{$q}%")
                ->orWhere('description', 'LIKE', "%{$q}%")
                ->latest('published_at')
                ->paginate(12)
                ->withQueryString();
        }

        $bookmarkedIds = auth()->check()
            ? auth()->user()->bookmarks()->pluck('article_id')->toArray()
            : [];

        return view('search.index', compact('articles', 'q', 'bookmarkedIds'));
    }

    public function liveSearch(Request $request)
    {
        $q = trim($request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $results = Article::where('title', 'LIKE', "%{$q}%")
            ->with('category')
            ->latest('published_at')
            ->take(6)
            ->get(['id', 'title', 'slug', 'image_url', 'published_at', 'category_id']);

        return response()->json($results->map(fn ($a) => [
            'title'    => $a->title,
            'url'      => route('articles.show', $a->slug),
            'image'    => $a->image_url,
            'category' => $a->category?->name,
            'date'     => $a->published_at?->diffForHumans(),
        ]));
    }
}
