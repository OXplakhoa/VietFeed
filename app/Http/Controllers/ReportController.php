<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Moderation\ModerationException;
use App\Moderation\ModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function store(Request $request, ModerationService $moderation, string $comment)
    {
        $data = $request->validate([
            'reason' => 'required|in:hate_speech,harassment,spam,misinformation,sexual_content,other',
            'description' => 'nullable|string|max:2000',
        ]);

        // Resolved by hand so a deleted comment gets a message instead of a 404.
        $target = Str::isUuid($comment) ? Comment::find($comment) : null;

        try {
            $moderation->reportComment($request->user(), $target, $data['reason'], $data['description'] ?? null);
        } catch (ModerationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cảm ơn bạn đã báo cáo. Đội ngũ của chúng tôi sẽ xem xét trong thời gian sớm nhất.');
    }
}
