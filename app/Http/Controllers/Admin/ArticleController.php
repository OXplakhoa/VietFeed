<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with(['source', 'category'])->withCount('bookmarks');

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
            $query->where('source_id', $request->source);
        }

        $articles   = $query->latest('published_at')->paginate(20)->withQueryString();
        $categories = Category::orderBy('name')->get();
        $sources    = Source::orderBy('name')->get();

        return view('admin.articles.index', compact('articles', 'categories', 'sources'));
    }

    public function show(Article $article)
    {
        $article->load(['source', 'category', 'comments' => fn ($q) => $q->with('user')->latest()]);

        return view('admin.articles.show', compact('article'));
    }

    public function edit(Article $article)
    {
        $categories = Category::orderBy('name')->get();
        $sources    = Source::orderBy('name')->get();

        return view('admin.articles.edit', compact('article', 'categories', 'sources'));
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:500',
            'description'  => 'nullable|string',
            'image_url'    => 'nullable|url|max:1000',
            'category_id'  => 'required|exists:categories,id',
            'source_id'    => 'required|exists:sources,id',
            'published_at' => 'nullable|date',
        ]);

        if ($request->title !== $article->title) {
            $slug = Str::slug($request->title);
            $base = $slug;
            $i    = 1;
            while (Article::where('slug', $slug)->where('id', '!=', $article->id)->exists()) {
                $slug = $base . '-' . $i++;
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
        $ids = array_filter((array) $request->input('ids', []), 'is_numeric');

        if (empty($ids)) {
            return back()->with('error', 'Không có bài viết nào được chọn.');
        }

        $count = Article::whereIn('id', $ids)->delete();

        return back()->with('success', "Đã xóa {$count} bài viết.");
    }
}
