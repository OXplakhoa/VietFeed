<?php

namespace App\Http\Controllers;

use App\Content\ArticleCounts;
use App\Interaction\InteractionToggle;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookmarkController extends Controller
{
    public function index()
    {
        /** @var User $user */
        $user = Auth::user();

        $bookmarks = $user
            ->bookmarks()
            ->with(['article.source', 'article.category'])
            ->latest()
            ->get();

        $articles = $bookmarks->pluck('article')->filter();
        ArticleCounts::attach($articles);

        return view('bookmarks.index', compact('articles'));
    }

    public function toggle(Request $request, InteractionToggle $toggle)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'unverified',
                'message' => 'Vui lòng xác minh email để lưu bài viết',
            ], 403);
        }

        if (! $user->canBookmark()) {
            return response()->json([
                'error' => 'restricted',
                'message' => 'Tài khoản đang bị hạn chế nên không thể lưu bài viết.',
            ], 403);
        }

        $request->validate(['article_id' => 'required|string|exists:mongodb.articles,_id']);

        return response()->json($toggle->run(Bookmark::class, (string) $user->getKey(), $request->string('article_id')->toString()));
    }
}
