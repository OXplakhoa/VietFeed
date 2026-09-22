<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OnboardingController extends Controller
{
    public function showInterests()
    {
        $categories = Category::active()->get();
        /** @var User $user */
        $user = Auth::user();
        // Gate 3: favorites are embedded on the user doc (category_user pivot retired).
        $selected = $user->favorite_category_ids ?? [];

        return view('onboarding.interests', compact('categories', 'selected'));
    }

    public function saveInterests(Request $request)
    {
        $request->validate([
            'categories' => 'array',
            'categories.*' => 'exists:categories,id,is_active,1',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->update(['favorite_category_ids' => array_values($request->categories ?? [])]);

        return redirect()->route('home')->with('success', 'Đã lưu sở thích của bạn!');
    }
}
