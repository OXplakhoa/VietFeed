<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function store(Request $request, Comment $comment)
    {
        abort_if($comment->user_id === Auth::id(), 403, 'Bạn không thể báo cáo bình luận của chính mình.');
        abort_if(Auth::user()->isAdmin(), 403, 'Quản trị viên không cần báo cáo.');

        $request->validate([
            'reason' => 'required|in:hate_speech,harassment,spam,misinformation,sexual_content,other',
            'description' => 'nullable|string|max:2000',
        ]);

        $existing = Report::where('reporter_id', Auth::id())
            ->where('comment_id', $comment->id)
            ->first();

        if ($existing) {
            return back()->with('error', 'Bạn đã báo cáo bình luận này rồi.');
        }

        Report::create([
            'reporter_id' => Auth::id(),
            'comment_id' => $comment->id,
            'reason' => $request->reason,
            'description' => $request->description,
        ]);

        return back()->with('success', 'Cảm ơn bạn đã báo cáo. Đội ngũ của chúng tôi sẽ xem xét trong thời gian sớm nhất.');
    }
}
