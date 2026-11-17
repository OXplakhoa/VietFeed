<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sanction;
use App\Models\User;
use App\Moderation\ModerationException;
use App\Moderation\ModerationQueries;
use App\Moderation\ModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SanctionController extends Controller
{
    public function index(Request $request, ModerationQueries $queries)
    {
        $request->validate([
            'type' => 'nullable|in:warning,mute,temporary_ban,permanent_ban',
            'status' => 'nullable|in:active,expired,revoked,pending_appeal',
            'q' => 'nullable|string|max:200',
        ]);

        $sanctions = $queries->sanctions($request->input('type'), $request->input('status'), $request->input('q'))->withQueryString();
        $pendingAppealsCount = $queries->pendingAppealsCount();

        return view('admin.sanctions.index', compact('sanctions', 'pendingAppealsCount'));
    }

    public function store(Request $request, ModerationService $moderation)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:mongodb.users,_id',
            'report_id' => 'nullable|exists:reports,id',
            'type' => 'required|in:warning,mute,temporary_ban,permanent_ban',
            'reason' => 'required|string|max:2000',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'hide_comment' => 'nullable|boolean',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $moderation->issueSanction(
                $request->user(),
                User::findOrFail($data['user_id']),
                $data['type'],
                $data['reason'],
                isset($data['duration_days']) ? (int) $data['duration_days'] : null,
                isset($data['report_id']) ? (int) $data['report_id'] : null,
                (bool) ($data['hide_comment'] ?? false),
                $data['note'] ?? null,
            );
        } catch (ModerationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $error) {
            Log::warning('Sanction save failed', ['exception_type' => $error::class]);

            return back()->with('error', 'Không thể áp dụng biện pháp xử lý. Vui lòng thử lại.');
        }

        return redirect()->route('admin.sanctions.index')->with('success', 'Biện pháp xử lý đã được áp dụng.');
    }

    public function show(Sanction $sanction, ModerationQueries $queries)
    {
        return view('admin.sanctions.show', $queries->sanctionDetail($sanction));
    }

    /** Decide a pending appeal. */
    public function update(Request $request, Sanction $sanction, ModerationService $moderation)
    {
        $data = $request->validate([
            'appeal_status' => 'required|in:approved,denied',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $moderation->decideAppeal($request->user(), $sanction, $data['appeal_status'], $data['note'] ?? null);
        } catch (ModerationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Kháng cáo đã được '.($data['appeal_status'] === 'approved' ? 'chấp nhận' : 'từ chối').'.');
    }

    public function revoke(Request $request, Sanction $sanction, ModerationService $moderation)
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        try {
            $moderation->revokeSanction($request->user(), $sanction, $data['note'] ?? null);
        } catch (ModerationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.sanctions.index')->with('success', 'Biện pháp xử lý đã được thu hồi.');
    }
}
