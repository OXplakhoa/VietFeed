<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;

class HomeController extends Controller
{
    public function index()
    {
        // Gate 3: articles live on Mongo — SQL score subqueries/joins can't run, so the
        // same formula (bookmarks×5 + comments×2 + prestige×4 − age_hours×0.5) is scored
        // in PHP over per-store counts. ponytail: full candidate scan; pre-aggregated
        // counters when article volume demands it.
        $activeSourceIds = Source::active()->pluck('id');
        $activeCategoryIds = Category::active()->pluck('id');

        $candidates = Article::with(['source', 'category'])
            ->whereIn('source_id', $activeSourceIds)
            ->whereIn('category_id', $activeCategoryIds)
            ->whereNotNull('image_url')
            ->get();
        $bmCounts = Bookmark::select('article_id')->selectRaw('COUNT(*) as n')->groupBy('article_id')->pluck('n', 'article_id');
        $cmCounts = Comment::select('article_id')->selectRaw('COUNT(*) as n')->groupBy('article_id')->pluck('n', 'article_id');
        $now = now();
        $ranked = $candidates->map(function ($article) use ($bmCounts, $cmCounts, $now) {
            $hoursOld = max(0, ($now->timestamp - ($article->published_at?->timestamp ?? $now->timestamp)) / 3600);
            $article->sensational_score = (($bmCounts[$article->getKey()] ?? 0) * 5)
                + (($cmCounts[$article->getKey()] ?? 0) * 2)
                + (($article->source->prestige ?? 3) * 4)
                - ($hoursOld * 0.5);

            return $article;
        })->sortByDesc('sensational_score')->values();

        // Featured: most sensational across ALL categories
        $featured = $ranked->first();
        $featuredId = $featured?->getKey();

        /** @var User|null $user */
        $user = Auth::user();

        // Small cards: personalized to user's favorite categories (embedded ids).
        $userCatIds = $user
            ? Category::where('is_active', true)->whereIn('id', $user->favorite_category_ids ?? [])->pluck('id')->toArray()
            : [];

        $heroArticles = $ranked
            ->when(! empty($userCatIds), fn ($collection) => $collection->whereIn('category_id', $userCatIds))
            ->reject(fn ($article) => $article->getKey() === $featuredId)
            ->take(5)->values();

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
            ->whereIn('source_id', $activeSourceIds)
            ->whereIn('category_id', $activeCategoryIds)
            ->whereNotIn('_id', array_merge($heroArticles->map->getKey()->all(), $usedArticleIds))
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

        $boostCounts = Boost::select('article_id')->selectRaw('COUNT(*) as n')->groupBy('article_id')->pluck('n', 'article_id');
        $withBoosts = fn ($articles) => $articles->each(fn ($article) => $article->boosts_count = (int) ($boostCounts[$article->getKey()] ?? 0));
        $orderBoosts = fn ($articles) => $articles->sortBy([['boosts_count', 'desc'], ['published_at', 'desc']])->values();

        $communityBoosted = $orderBoosts($withBoosts(Article::with(['source', 'category'])
            ->whereIn('source_id', $activeSourceIds)
            ->whereIn('category_id', $activeCategoryIds)
            ->where('published_at', '>=', now()->subHours(72))
            ->get()))->take(6)->values();

        if ($communityBoosted->count() < 6) {
            $communityBoosted = $communityBoosted->concat(
                $withBoosts(Article::with(['source', 'category'])
                    ->whereIn('source_id', $activeSourceIds)
                    ->whereIn('category_id', $activeCategoryIds)
                    ->whereNotIn('_id', $communityBoosted->map->getKey()->all())
                    ->latest('published_at')
                    ->take(6 - $communityBoosted->count())
                    ->get())
            );
        }

        if ($breakingArticles->count() < 5) {
            $fillers = Article::with(['source', 'category'])
                ->whereIn('source_id', $activeSourceIds)
                ->whereIn('category_id', $activeCategoryIds)
                ->whereNotIn('_id', $breakingArticles->map->getKey()->merge($heroArticles->map->getKey())->all())
                ->latest('published_at')
                ->take(5 - $breakingArticles->count())
                ->get();

            $breakingArticles = $breakingArticles->merge($fillers);
        }

        // Main feed (article-card falls back to live counts when unset).
        $query = Article::with(['source', 'category'])
            ->whereIn('source_id', $activeSourceIds)
            ->whereIn('category_id', $activeCategoryIds);

        if (! empty($userCatIds)) {
            $query->whereIn('category_id', $userCatIds);
        }

        $articles = $query->whereNotIn('_id', $heroIds)
            ->latest('published_at')
            ->paginate(12);

        $trending = $orderBoosts($withBoosts(Article::with(['source', 'category'])
            ->whereIn('source_id', $activeSourceIds)
            ->whereIn('category_id', $activeCategoryIds)
            ->where('published_at', '>=', now()->subHours(72))
            ->get()))->take(5)->values();

        if ($trending->count() < 5) {
            $trending = $trending->concat(
                $withBoosts(Article::with(['source', 'category'])
                    ->whereIn('source_id', $activeSourceIds)
                    ->whereIn('category_id', $activeCategoryIds)
                    ->whereNotIn('_id', $trending->map->getKey()->all())
                    ->latest('published_at')
                    ->take(5 - $trending->count())
                    ->get())
            );
        }

        $bookmarkedIds = $user
            ? $user->bookmarks()->pluck('article_id')->toArray()
            : [];

        $boostedIds = $user
            ? $user->boosts()->pluck('article_id')->toArray()
            : [];

        $categories = Category::active()->get();

        return view('home.index', compact(
            'featured', 'heroArticles', 'breakingArticles', 'communityBoosted', 'articles', 'trending', 'bookmarkedIds', 'boostedIds', 'categories'
        ));
    }

    public function loadMore(Request $request)
    {
        $page = max(1, (int) $request->get('page', 2));

        // Gate 3: withCount SQL subqueries can't join to Mongo; article-card falls
        // back to live counts when unset.
        $query = Article::with(['source', 'category'])
            ->whereHas('category', fn ($qb) => $qb->active())
            ->whereHas('source', fn ($qb) => $qb->active());

        /** @var User|null $user */
        $user = Auth::user();

        // Gate 3: favorites are embedded ids (pivot retired); active-only filter stays SQL.
        if ($user && ! empty($user->favorite_category_ids)) {
            $catIds = Category::where('is_active', true)->whereIn('id', $user->favorite_category_ids)->pluck('id');
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
