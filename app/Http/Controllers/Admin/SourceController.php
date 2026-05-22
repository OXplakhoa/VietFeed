<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSourceRequest;
use App\Http\Requests\Admin\UpdateSourceRequest;
use App\Models\Category;
use App\Models\Source;

class SourceController extends Controller
{
    public function index()
    {
        $sources = Source::with('category')
            ->withCount('articles')
            ->orderBy('category_id')
            ->orderBy('name')
            ->paginate(20);

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
