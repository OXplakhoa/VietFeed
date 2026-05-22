<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::withCount(['articles', 'sources']);

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', "%{$request->q}%");
        }

        $sort         = $request->input('sort', 'name');
        $dir          = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['name', 'articles_count'];
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $dir);
        }

        $categories = $query->paginate(20)->withQueryString();

        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(StoreCategoryRequest $request)
    {
        Category::create($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', 'Đã thêm chủ đề mới.');
    }

    public function edit(Category $category)
    {
        $category->loadCount(['articles', 'sources']);

        return view('admin.categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', 'Đã cập nhật chủ đề.');
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
