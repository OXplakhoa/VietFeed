<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    private const COUNT_CHUNK_SIZE = 500;

    protected int $countChunkSize = self::COUNT_CHUNK_SIZE;

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'source' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return redirect()->route('admin.articles.index')->withErrors($validator);
        }

        $query = Article::with(['source', 'category']);

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($qb) => $qb
                ->where('title', 'LIKE', "%{$q}%")
                ->orWhere('description', 'LIKE', "%{$q}%")
            );
        }

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($qb) => $qb->where('slug', $request->category));
        }

        if ($request->filled('source')) {
            $query->where('source_id', $request->integer('source'));
        }

        if ($request->filled('date_from')) {
            $query->where('published_at', '>=', $request->date('date_from')->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('published_at', '<=', $request->date('date_to')->endOfDay());
        }

        $query->latest('published_at')->orderBy('_id');
        if (in_array($request->get('sort'), ['boosts', 'bookmarks'], true)) {
            // Project ids only in published order; never hydrate the candidate set.
            $ids = $query->toBase()->pluck('id')->all();
            $model = $request->get('sort') === 'boosts' ? Boost::class : Bookmark::class;
            $counts = [];
            // Bound SQL parameters (including SQLite's conservative 999 limit).
            foreach (array_chunk($ids, $this->countChunkSize) as $chunk) {
                $counts += $model::select('article_id')->selectRaw('COUNT(*) as n')
                    ->whereIn('article_id', $chunk)->groupBy('article_id')
                    ->orderByDesc('n')->pluck('n', 'article_id')->all();
            }
            $publishedOrder = array_flip($ids);
            usort($ids, fn ($a, $b) => (($counts[$b] ?? 0) <=> ($counts[$a] ?? 0))
                ?: ($publishedOrder[$a] <=> $publishedOrder[$b]));
            $page = LengthAwarePaginator::resolveCurrentPage();
            $pageIds = array_slice($ids, ($page - 1) * 20, 20);
            $pageOrder = array_flip($pageIds);
            $items = Article::with(['source', 'category'])->whereIn('_id', $pageIds)->get()
                ->sortBy(fn ($article) => $pageOrder[$article->getKey()])->values();
            $articles = new LengthAwarePaginator(
                $items, count($ids), 20, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $articles = $query->paginate(20)->withQueryString();
        }
        $this->attachCounts($articles->getCollection(), [
            'bookmarks' => Bookmark::class, 'boosts' => Boost::class,
        ]);
        $categories = Category::orderBy('name')->get();
        $sources = Source::orderBy('name')->get();

        return view('admin.articles.index', compact('articles', 'categories', 'sources'));
    }

    public function show(Article $article)
    {
        $article->load(['source', 'category', 'comments' => fn ($q) => $q->with('user')->latest()]);

        $this->attachCounts(collect([$article]), [
            'bookmarks' => Bookmark::class, 'boosts' => Boost::class, 'unlocks' => ArticleUnlock::class,
        ]);

        return view('admin.articles.show', compact('article'));
    }

    private function attachCounts(Collection $articles, array $types): void
    {
        if ($articles->isEmpty()) {
            return;
        }

        $ids = $articles->map->getKey()->all();
        foreach ($types as $name => $model) {
            $counts = $model::select('article_id')->selectRaw('COUNT(*) as n')
                ->whereIn('article_id', $ids)->groupBy('article_id')->pluck('n', 'article_id');
            foreach ($articles as $article) {
                $article->setAttribute($name.'_count', (int) ($counts[$article->getKey()] ?? 0));
            }
        }
    }

    public function edit(Article $article)
    {
        $categories = Category::orderBy('name')->get();
        $sources = Source::orderBy('name')->get();

        return view('admin.articles.edit', compact('article', 'categories', 'sources'));
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'image_url' => 'nullable|url|max:1000',
            'category_id' => [! $article->category_id && $article->category_snapshot ? 'nullable' : 'required', 'exists:categories,id'],
            'source_id' => [! $article->source_id && $article->source_snapshot ? 'nullable' : 'required', 'exists:sources,id'],
            'published_at' => 'nullable|date',
        ]);

        // Match RSS ingestion's SQL integer identities; snapshots retain null ids.
        foreach (['source_id', 'category_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        if ($request->title !== $article->title) {
            $slug = Str::slug($request->title);
            $base = $slug;
            $i = 1;
            while (Article::where('slug', $slug)->where('id', '!=', $article->id)->exists()) {
                $slug = $base.'-'.$i++;
            }
            $data['slug'] = $slug;
        }

        $article->update($data);

        return redirect()->route('admin.articles.index')
            ->with('success', 'Đã cập nhật bài viết.');
    }

    public function destroy(Article $article)
    {
        $article->delete();

        return back()->with('success', 'Đã xóa bài viết.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter((array) $request->input('ids', []), fn ($id) => is_numeric($id) || (is_string($id) && Str::isUuid($id)));

        if (empty($ids)) {
            return back()->with('error', 'Không có bài viết nào được chọn.');
        }

        $count = Article::whereIn('id', $ids)->delete();

        return back()->with('success', "Đã xóa {$count} bài viết.");
    }
}
