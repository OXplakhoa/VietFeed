<?php

namespace App\Http\Controllers;

use App\Models\Boost;
use Illuminate\Http\Request;

class BoostController extends Controller
{
    public function toggle(Request $request)
    {
        $request->validate(['article_id' => 'required|exists:articles,id']);

        $existing = $request->user()
            ->boosts()
            ->where('article_id', $request->integer('article_id'))
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            $request->user()->boosts()->create([
                'article_id' => $request->integer('article_id'),
            ]);
            $action = 'added';
        }

        $count = Boost::where('article_id', $request->integer('article_id'))->count();

        return response()->json(['action' => $action, 'count' => $count]);
    }
}
