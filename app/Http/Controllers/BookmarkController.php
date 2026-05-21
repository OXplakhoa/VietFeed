<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function index()
    {
        $bookmarks = auth()->user()
            ->bookmarks()
            ->with(['article.source', 'article.category'])
            ->latest()
            ->get();

        $articles = $bookmarks->pluck('article')->filter();

        return view('bookmarks.index', compact('articles'));
    }

    public function toggle(Request $request)
    {
        if (! auth()->user()->hasVerifiedEmail()) {
            return response()->json([
                'error'   => 'unverified',
                'message' => 'Vui lòng xác minh email để lưu bài viết',
            ], 403);
        }

        $request->validate(['article_id' => 'required|exists:articles,id']);

        $existing = auth()->user()
            ->bookmarks()
            ->where('article_id', $request->article_id)
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            auth()->user()->bookmarks()->create(['article_id' => $request->article_id]);
            $action = 'added';
        }

        $count = Bookmark::where('article_id', $request->article_id)->count();

        return response()->json(['action' => $action, 'count' => $count]);
    }
}
