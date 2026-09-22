<?php

namespace App\Http\Controllers;

use App\Models\Boost;
use Illuminate\Http\Request;

class BoostController extends Controller
{
    public function toggle(Request $request)
    {
        $request->validate(['article_id' => 'required|string|exists:mongodb.articles,_id']);

        $existing = $request->user()
            ->boosts()
            ->where('article_id', $request->string('article_id')->toString())
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            $request->user()->boosts()->create([
                'article_id' => $request->string('article_id')->toString(),
            ]);
            $action = 'added';
        }

        $count = Boost::where('article_id', $request->string('article_id')->toString())->count();

        return response()->json(['action' => $action, 'count' => $count]);
    }
}
