<?php

namespace App\Http\Controllers;

use App\Content\ArticleCounts;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with(['source', 'category'])
            ->whereIn('category_id', Category::active()->pluck('id'))
            ->whereIn('source_id', Source::active()->pluck('id'));

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qb) use ($q) {
                $qb->where('title', 'LIKE', "%{$q}%")
                    ->orWhere('description', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category')) {
            $query->whereIn('category_id', Category::active()->where('slug', $request->category)->pluck('id'));
        }

        if ($request->filled('source')) {
            $query->where('source_id', $request->source);
        }

        $articles = $query->latest('published_at')->paginate(12)->withQueryString();
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
        $sources = Source::active()->orderBy('name')->get();

        return view('articles.index', compact('articles', 'bookmarkedIds', 'boostedIds', 'categories', 'sources'));
    }

    public function show(string $slug, ReadingPassService $readingPass)
    {
        // Gate 3: articles live on Mongo — withCount SQL subqueries can't join;
        // attach the same counts with native per-store queries.
        $article = Article::where('slug', $slug)
            ->with(['source', 'category'])
            ->whereIn('category_id', Category::active()->pluck('id'))
            ->whereIn('source_id', Source::active()->pluck('id'))
            ->firstOrFail();

        /** @var User|null $user */
        $user = Auth::user();

        $readingPassState = $readingPass->accessOrLock($article, $user);
        $canAccessArticle = $readingPassState['canAccess'];
        $readingPassAllowance = $readingPassState['allowance'];

        if ($canAccessArticle) {
            $article->load([
                'comments' => fn ($q) => $q->whereNull('parent_id')
                    ->visibleTo($user)
                    ->with(['user', 'replies' => fn ($r) => $r->visibleTo($user)->with('user')])
                    ->latest(),
            ]);
        } else {
            $article->setRelation('comments', collect());
        }

        $isBookmarked = $user
            ? $user->bookmarks()->where('article_id', $article->id)->exists()
            : false;

        $isBoosted = $user
            ? $user->boosts()->where('article_id', $article->id)->exists()
            : false;

        $related = Article::with(['source', 'category'])
            ->whereIn('category_id', Category::active()->pluck('id'))
            ->whereIn('source_id', Source::active()->pluck('id'))
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->latest('published_at')
            ->take(10)
            ->get();

        if ($related->count() < 10) {
            $related = $related->concat(
                Article::with(['source', 'category'])
                    ->whereIn('category_id', Category::active()->pluck('id'))
                    ->whereIn('source_id', Source::active()->pluck('id'))
                    ->where('id', '!=', $article->id)
                    ->whereNotIn('id', $related->pluck('id'))
                    ->latest('published_at')
                    ->take(10 - $related->count())
                    ->get()
            );
        }

        ArticleCounts::attach(collect([$article])->concat($related));

        // Per-viewer sanction checks run once per request, never per rendered comment.
        $blockingSanction = $user?->blockingCommentSanction();
        $viewerCanComment = $user !== null && $blockingSanction === null;
        $viewerCanReport = $user?->canReport() ?? false;

        return view('articles.show', compact('article', 'isBookmarked', 'isBoosted', 'related', 'canAccessArticle', 'readingPassAllowance', 'viewerCanComment', 'viewerCanReport', 'blockingSanction'));
    }
}
