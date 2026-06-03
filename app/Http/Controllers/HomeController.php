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
        // Database-agnostic time decay: hours since published
        $driver = config('database.default');
        if ($driver === 'sqlite') {
            $hoursOld = "((strftime('%s', 'now') - strftime('%s', articles.published_at)) / 3600.0)";
        } else {
            $hoursOld = 'TIMESTAMPDIFF(HOUR, articles.published_at, NOW())';
        }

        // Inline subqueries for counts — withCount() aliases can't be referenced in SELECT list on MySQL
        $bmCount = '(SELECT COUNT(*) FROM bookmarks WHERE bookmarks.article_id = articles.id)';
        $cmCount = '(SELECT COUNT(*) FROM comments WHERE comments.article_id = articles.id)';
        $scoreFormula = "({$bmCount} * 5) + ({$cmCount} * 2) + (sources.prestige * 4) - ({$hoursOld} * 0.5)";

        $heroBase = Article::select('articles.*')
            ->selectRaw($scoreFormula.' as sensational_score')
            ->withCount('boosts')
            ->join('sources', 'articles.source_id', '=', 'sources.id')
            ->whereNotNull('articles.image_url');

        // Featured: most sensational across ALL categories
        $featured = (clone $heroBase)->orderByDesc('sensational_score')->first();
        $featuredId = $featured ? $featured->id : null;

        // Small cards: personalized to user's favorite categories
        $userCatIds = auth()->check()
            ? auth()->user()->favoriteCategories()->pluck('categories.id')->toArray()
            : [];

        $heroArticles = (clone $heroBase)
            ->when(! empty($userCatIds), fn ($q) => $q->whereIn('articles.category_id', $userCatIds))
            ->when($featuredId, fn ($q) => $q->where('articles.id', '!=', $featuredId))
            ->orderByDesc('sensational_score')
            ->take(5)
            ->get();

        // Collect hero article IDs to exclude from main feed
        $heroIds = collect();
        if ($featuredId) {
            $heroIds->push($featuredId);
        }
        $heroIds = $heroIds->merge($heroArticles->pluck('id'))->toArray();

        $breakingArticles = collect();
        if ($featured) {
            $breakingArticles->push($featured);
        }

        $usedCategories = $breakingArticles->pluck('category_id')->filter()->all();
        $usedArticleIds = $breakingArticles->pluck('id')->all();

        $diverseBreaking = Article::with(['source', 'category'])
            ->whereNotIn('id', array_merge($heroArticles->pluck('id')->all(), $usedArticleIds))
            ->latest('published_at')
            ->take(40)
            ->get()
            ->filter(function ($article) use (&$usedCategories) {
                if (in_array($article->category_id, $usedCategories, true)) {
                    return false;
                }

                $usedCategories[] = $article->category_id;

                return true;
            })
            ->take(5 - $breakingArticles->count());

        $breakingArticles = $breakingArticles->merge($diverseBreaking);

        $communityBoosted = Article::with(['source', 'category'])
            ->withCount('boosts')
            ->where('published_at', '>=', now()->subHours(72))
            ->orderByDesc('boosts_count')
            ->latest('published_at')
            ->take(6)
            ->get();

        if ($communityBoosted->count() < 6) {
            $communityBoosted = $communityBoosted->concat(
                Article::with(['source', 'category'])
                    ->withCount('boosts')
                    ->whereNotIn('id', $communityBoosted->pluck('id'))
                    ->latest('published_at')
                    ->take(6 - $communityBoosted->count())
                    ->get()
            );
        }

        if ($breakingArticles->count() < 5) {
            $fillers = Article::with(['source', 'category'])
                ->whereNotIn('id', $breakingArticles->pluck('id')->merge($heroArticles->pluck('id'))->all())
                ->latest('published_at')
                ->take(5 - $breakingArticles->count())
                ->get();

            $breakingArticles = $breakingArticles->merge($fillers);
        }

        // Main feed
        $query = Article::with(['source', 'category'])->withCount(['bookmarks', 'boosts']);

        if (! empty($userCatIds)) {
            $query->whereIn('category_id', $userCatIds);
        }

        $articles = $query->whereNotIn('id', $heroIds)
            ->latest('published_at')
            ->paginate(12);

        $trending = Article::withCount('boosts')
            ->where('published_at', '>=', now()->subHours(72))
            ->orderByDesc('boosts_count')
            ->latest('published_at')
            ->take(5)
            ->get();

        if ($trending->count() < 5) {
            $trending = $trending->concat(
                Article::withCount('boosts')
                    ->whereNotIn('id', $trending->pluck('id'))
                    ->latest('published_at')
                    ->take(5 - $trending->count())
                    ->get()
            );
        }

        $bookmarkedIds = auth()->check()
            ? auth()->user()->bookmarks()->pluck('article_id')->toArray()
            : [];

        $boostedIds = auth()->check()
            ? auth()->user()->boosts()->pluck('article_id')->toArray()
            : [];

        $categories = Category::all();

        return view('home.index', compact(
            'featured', 'heroArticles', 'breakingArticles', 'communityBoosted', 'articles', 'trending', 'bookmarkedIds', 'boostedIds', 'categories'
        ));
    }

    public function loadMore(Request $request)
    {
        $page = max(1, (int) $request->get('page', 2));

        $query = Article::with(['source', 'category'])->withCount(['bookmarks', 'boosts']);

        if (auth()->check() && auth()->user()->favoriteCategories()->count() > 0) {
            $catIds = auth()->user()->favoriteCategories()->pluck('categories.id');
            $query->whereIn('category_id', $catIds);
        }

        $articles = $query->latest('published_at')->paginate(12, ['*'], 'page', $page);

        $html = '';
        foreach ($articles->items() as $article) {
            $html .= '<div class="col-sm-6 col-lg-4 mb-4">'
                .Blade::render(
                    '<x-article-card :article="$article" :bookmarked="$bm" :boosted="$boosted" />',
                    ['article' => $article, 'bm' => false, 'boosted' => false]
                )
                .'</div>';
        }

        return response()->json([
            'html' => $html,
            'hasMore' => $articles->hasMorePages(),
            'nextPage' => $page + 1,
        ]);
    }
}
