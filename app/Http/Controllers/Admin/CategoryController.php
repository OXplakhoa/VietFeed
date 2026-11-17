<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Source;
use App\Support\MongoCounts;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query();

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', "%{$request->q}%");
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $sort = $request->input('sort', 'name');
        $descending = $request->input('dir', 'asc') === 'desc';
        $articles = MongoCounts::byField('category_id');
        $sources = Source::pluck('category_id')->countBy();
        $items = $query->get()->each(function ($category) use ($articles, $sources) {
            $category->articles_count = (int) ($articles[$category->id] ?? 0);
            $category->sources_count = (int) ($sources[$category->id] ?? 0);
        })->sortBy($sort === 'articles_count' ? 'articles_count' : 'name', SORT_REGULAR, $descending)->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $categories = new LengthAwarePaginator(
            $items->forPage($page, 20), $items->count(), 20, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(StoreCategoryRequest $request)
    {
        Category::create($request->validated() + ['is_active' => true]);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Đã thêm chủ đề mới.');
    }

    public function edit(Category $category)
    {
        $category->articles_count = $category->articles()->count();
        $category->sources_count = $category->sources()->count();

        return view('admin.categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', 'Đã cập nhật chủ đề.');
    }

    public function toggleActive(Category $category)
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'Đã bật lại chủ đề. Người dùng sẽ thấy tin thuộc chủ đề này.' : 'Đã tắt chủ đề. Người dùng sẽ không thấy tin thuộc chủ đề này.');
    }

    public function destroy(Category $category)
    {
        if ($category->articles()->exists() || $category->sources()->exists()) {
            return back()->with('error', 'Không thể xóa chủ đề đang có bài viết hoặc nguồn tin.');
        }

        $category->delete();

        return redirect()->route('admin.categories.index')
            ->with('success', 'Đã xóa chủ đề.');
    }
}
