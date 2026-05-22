<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request)
    {
        $query = Comment::with(['user', 'article'])->latest();

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where('body', 'LIKE', "%{$q}%");
        }

        $comments = $query->paginate(25)->withQueryString();

        return view('admin.comments.index', compact('comments'));
    }

    public function destroy(Comment $comment)
    {
        $comment->delete();

        return back()->with('success', 'Đã xóa bình luận.');
    }
}
