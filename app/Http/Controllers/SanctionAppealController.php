<?php

namespace App\Http\Controllers;

use App\Models\Sanction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SanctionAppealController extends Controller
{
    public function store(Request $request, Sanction $sanction)
    {
        abort_if($sanction->user_id !== Auth::id(), 403);
        abort_if($sanction->appeal_status !== 'none', 403, 'Bạn chỉ có thể kháng cáo một lần.');

        $request->validate([
            'appeal_message' => 'required|string|min:10|max:2000',
        ]);

        $sanction->update([
            'appeal_message' => $request->appeal_message,
            'appeal_status' => 'pending',
        ]);

        return back()->with('success', 'Kháng cáo của bạn đã được gửi. Chúng tôi sẽ xem xét trong thời gian sớm nhất.');
    }
}
