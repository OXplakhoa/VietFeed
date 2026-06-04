<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Services\ReadingPassService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with(['source', 'category'])->withCount(['bookmarks', 'boosts']);

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qb) use ($q) {
                $qb->where('title', 'LIKE', "%{$q}%")
                    ->orWhere('description', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($qb) => $qb->where('slug', $request->category));
        }

        if ($request->filled('source')) {
            $query->where('source_id', $request->source);
        }

        $articles = $query->latest('published_at')->paginate(12)->withQueryString();

        $bookmarkedIds = Auth::check()
            ? Auth::user()->bookmarks()->pluck('article_id')->toArray()
            : [];

        $boostedIds = Auth::check()
            ? Auth::user()->boosts()->pluck('article_id')->toArray()
            : [];

        $categories = Category::all();
        $sources = Source::where('is_active', true)->orderBy('name')->get();

        return view('articles.index', compact('articles', 'bookmarkedIds', 'boostedIds', 'categories', 'sources'));
    }

    public function show(string $slug, ReadingPassService $readingPass)
    {
        $article = Article::where('slug', $slug)
            ->with(['source', 'category'])
            ->withCount(['bookmarks', 'boosts'])
            ->firstOrFail();

        $readingPassState = $readingPass->accessOrLock($article, Auth::user());
        $canAccessArticle = $readingPassState['canAccess'];
        $readingPassAllowance = $readingPassState['allowance'];

        if ($canAccessArticle) {
            $article->load([
                'comments' => fn ($q) => $q->whereNull('parent_id')
                    ->with(['user', 'replies.user'])
                    ->latest(),
            ]);
        } else {
            $article->setRelation('comments', collect());
        }

        $isBookmarked = Auth::check()
            ? Auth::user()->bookmarks()->where('article_id', $article->id)->exists()
            : false;

        $isBoosted = Auth::check()
            ? Auth::user()->boosts()->where('article_id', $article->id)->exists()
            : false;

        $related = Article::with(['source', 'category'])
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->latest('published_at')
            ->take(10)
            ->get();

        if ($related->count() < 10) {
            $related = $related->concat(
                Article::with(['source', 'category'])
                    ->where('id', '!=', $article->id)
                    ->whereNotIn('id', $related->pluck('id'))
                    ->latest('published_at')
                    ->take(10 - $related->count())
                    ->get()
            );
        }

        return view('articles.show', compact('article', 'isBookmarked', 'isBoosted', 'related', 'canAccessArticle', 'readingPassAllowance'));
    }
}
