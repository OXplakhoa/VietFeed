<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function showInterests()
    {
        $categories = Category::all();
        $selected   = auth()->user()->favoriteCategories()->pluck('categories.id')->toArray();

        return view('onboarding.interests', compact('categories', 'selected'));
    }

    public function saveInterests(Request $request)
    {
        $request->validate([
            'categories'   => 'array',
            'categories.*' => 'exists:categories,id',
        ]);

        auth()->user()->favoriteCategories()->sync($request->categories ?? []);

        return redirect()->route('home')->with('success', 'Đã lưu sở thích của bạn!');
    }
}
