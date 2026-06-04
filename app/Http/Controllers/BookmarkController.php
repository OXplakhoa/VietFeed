<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookmarkController extends Controller
{
    public function index()
    {
        $bookmarks = Auth::user()
            ->bookmarks()
            ->with(['article.source', 'article.category'])
            ->latest()
            ->get();

        $articles = $bookmarks->pluck('article')->filter();

        return view('bookmarks.index', compact('articles'));
    }

    public function toggle(Request $request)
    {
        if (! Auth::user()->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'unverified',
                'message' => 'Vui lòng xác minh email để lưu bài viết',
            ], 403);
        }

        $request->validate(['article_id' => 'required|exists:articles,id']);

        $existing = Auth::user()
            ->bookmarks()
            ->where('article_id', $request->article_id)
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            Auth::user()->bookmarks()->create(['article_id' => $request->article_id]);
            $action = 'added';
        }

        $count = Bookmark::where('article_id', $request->article_id)->count();

        return response()->json(['action' => $action, 'count' => $count]);
    }
}
