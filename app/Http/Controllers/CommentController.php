<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Comment;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request, Article $article)
    {
        $request->validate([
            'body'      => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $article->comments()->create([
            'user_id'   => auth()->id(),
            'parent_id' => $request->parent_id,
            'body'      => $request->body,
        ]);

        return back()->with('success', 'Bình luận đã được đăng.');
    }

    public function update(Request $request, Comment $comment)
    {
        abort_if($comment->user_id !== auth()->id(), 403);

        $request->validate(['body' => 'required|string|max:2000']);

        $comment->update(['body' => $request->body]);

        return back()->with('success', 'Bình luận đã được cập nhật.');
    }

    public function destroy(Comment $comment)
    {
        abort_if($comment->user_id !== auth()->id() && !auth()->user()->isAdmin(), 403);

        $comment->delete();

        return back()->with('success', 'Bình luận đã được xóa.');
    }
}
