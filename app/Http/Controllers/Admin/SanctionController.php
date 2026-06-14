<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SanctionController extends Controller
{
    public function index(Request $request)
    {
        $query = Sanction::with(['user', 'admin', 'report'])
            ->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'expired') {
                $query->where(function ($q) {
                    $q->where('is_active', false)
                        ->orWhere(function ($sub) {
                            $sub->whereNotNull('expires_at')
                                ->where('expires_at', '<=', now());
                        });
                });
            } elseif ($request->status === 'pending_appeal') {
                $query->pendingAppeals();
            }
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                ->orWhere('reason', 'like', "%{$q}%");
        }

        $sanctions = $query->paginate(20)->withQueryString();
        $pendingAppealsCount = Sanction::pendingAppeals()->count();

        return view('admin.sanctions.index', compact('sanctions', 'pendingAppealsCount'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'report_id' => 'nullable|exists:reports,id',
            'comment_id' => 'nullable|exists:comments,id',
            'type' => 'required|in:warning,mute,temporary_ban,permanent_ban',
            'reason' => 'required|string|max:2000',
            'duration_days' => 'nullable|integer|min:1|max:365',
        ]);

        $user = User::findOrFail($request->user_id);

        $expiresAt = null;
        if (in_array($request->type, ['mute', 'temporary_ban'])) {
            $expiresAt = now()->addDays($request->duration_days ?? 7);
        }

        $sanction = Sanction::create([
            'user_id' => $request->user_id,
            'admin_id' => Auth::id(),
            'report_id' => $request->report_id,
            'type' => $request->type,
            'reason' => $request->reason,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        // If a comment_id was provided, soft-hide it
        if ($request->filled('comment_id')) {
            $comment = Comment::find($request->comment_id);
            if ($comment) {
                $comment->update(['is_hidden' => true]);
            }
        }

        // If a report_id was provided, update its status
        if ($request->filled('report_id')) {
            $report = Report::find($request->report_id);
            if ($report) {
                $report->update([
                    'status' => 'actioned',
                    'admin_id' => Auth::id(),
                    'reviewed_at' => now(),
                ]);
            }
        }

        return redirect()->route('admin.sanctions.index')->with('success', 'Biện pháp xử lý đã được áp dụng.');
    }

    public function show(Sanction $sanction)
    {
        $sanction->load(['user', 'admin', 'report.comment', 'report.reporter']);

        return view('admin.sanctions.show', compact('sanction'));
    }

    public function update(Request $request, Sanction $sanction)
    {
        $request->validate([
            'appeal_status' => 'required|in:approved,denied',
        ]);

        $appealStatus = $request->appeal_status;

        $update = ['appeal_status' => $appealStatus];

        if ($appealStatus === 'approved') {
            $update['is_active'] = false;
        }

        $sanction->update($update);

        return back()->with('success', 'Kháng cáo đã được '.($appealStatus === 'approved' ? 'chấp nhận' : 'từ chối').'.');
    }

    public function destroy(Sanction $sanction)
    {
        $sanction->delete();

        return redirect()->route('admin.sanctions.index')->with('success', 'Biện pháp xử lý đã được xóa.');
    }
}
