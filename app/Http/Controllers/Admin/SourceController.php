<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSourceRequest;
use App\Http\Requests\Admin\UpdateSourceRequest;
use App\Models\Category;
use App\Models\Source;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(Request $request)
    {
        $query = Source::with('category')->withCount('articles');

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', "%{$request->q}%");
        }

        $sort         = $request->input('sort', 'name');
        $dir          = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['name', 'articles_count', 'last_fetched_at'];
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $dir);
        }

        $sources = $query->paginate(20)->withQueryString();

        return view('admin.sources.index', compact('sources'));
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();

        return view('admin.sources.create', compact('categories'));
    }

    public function store(StoreSourceRequest $request)
    {
        Source::create($request->validated());

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã thêm nguồn tin mới.');
    }

    public function show(Source $source)
    {
        $source->load('category');
        $articles = $source->articles()
            ->withCount('bookmarks')
            ->latest('published_at')
            ->paginate(15);

        return view('admin.sources.show', compact('source', 'articles'));
    }

    public function edit(Source $source)
    {
        $categories = Category::orderBy('name')->get();

        return view('admin.sources.edit', compact('source', 'categories'));
    }

    public function update(UpdateSourceRequest $request, Source $source)
    {
        $source->update($request->validated());

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã cập nhật nguồn tin.');
    }

    public function destroy(Source $source)
    {
        $source->delete();

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã xóa nguồn tin.');
    }
}
