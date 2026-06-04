<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OnboardingController extends Controller
{
    public function showInterests()
    {
        $categories = Category::all();
        $selected = Auth::user()->favoriteCategories()->pluck('categories.id')->toArray();

        return view('onboarding.interests', compact('categories', 'selected'));
    }

    public function saveInterests(Request $request)
    {
        $request->validate([
            'categories' => 'array',
            'categories.*' => 'exists:categories,id',
        ]);

        Auth::user()->favoriteCategories()->sync($request->categories ?? []);

        return redirect()->route('home')->with('success', 'Đã lưu sở thích của bạn!');
    }
}
