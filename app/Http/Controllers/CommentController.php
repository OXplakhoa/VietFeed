<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{
    public function store(Request $request, Article $article)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canComment()) {
            $sanction = $user->activeSanction();
            $msg = 'Bạn không thể bình luận.';
            if ($sanction) {
                $msg = match ($sanction->type) {
                    'mute' => 'Bạn đang bị cấm bình luận. Hết hạn: '.$sanction->expires_at?->format('d/m/Y H:i').'.',
                    'temporary_ban' => 'Tài khoản bị hạn chế. Hết hạn: '.$sanction->expires_at?->format('d/m/Y H:i').'.',
                    'permanent_ban' => 'Tài khoản đã bị cấm vĩnh viễn.',
                    default => 'Bạn không thể bình luận.',
                };
            }

            return back()->with('error', $msg);
        }

        $request->validate([
            'body' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $article->comments()->create([
            'user_id' => Auth::id(),
            'parent_id' => $request->parent_id,
            'body' => $request->body,
        ]);

        return back()->with('success', 'Bình luận đã được đăng.');
    }

    public function update(Request $request, Comment $comment)
    {
        abort_if($comment->user_id !== Auth::id(), 403);

        $request->validate(['body' => 'required|string|max:2000']);

        $comment->update(['body' => $request->body]);

        return back()->with('success', 'Bình luận đã được cập nhật.');
    }

    public function destroy(Comment $comment)
    {
        /** @var User $user */
        $user = Auth::user();

        abort_if($comment->user_id !== Auth::id() && ! $user->isAdmin(), 403);

        $comment->delete();

        return back()->with('success', 'Bình luận đã được xóa.');
    }
}
