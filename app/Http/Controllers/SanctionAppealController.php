<?php

namespace App\Http\Controllers;

use App\Models\Sanction;
use App\Moderation\ModerationException;
use App\Moderation\ModerationService;
use Illuminate\Http\Request;

class SanctionAppealController extends Controller
{
    public function store(Request $request, ModerationService $moderation, Sanction $sanction)
    {
        $data = $request->validate([
            'appeal_message' => 'required|string|min:10|max:2000',
        ]);

        try {
            $moderation->submitAppeal($request->user(), $sanction, $data['appeal_message']);
        } catch (ModerationException $e) {
            abort_if($e->status === 403, 403, $e->getMessage());

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Kháng cáo của bạn đã được gửi. Chúng tôi sẽ xem xét trong thời gian sớm nhất.');
    }
}
