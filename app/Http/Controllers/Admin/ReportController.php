<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $query = Report::with(['reporter', 'comment.user', 'comment.article'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('reason')) {
            $query->where('reason', $request->reason);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->whereHas('reporter', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('comment', fn ($c) => $c->where('body', 'like', "%{$q}%"))
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        // Group reports by comment for the inbox view
        $reports = $query->paginate(20)->withQueryString();

        // Get grouped counts for the badge display
        $groupedCounts = Report::selectRaw('comment_id, COUNT(*) as count, MAX(created_at) as latest')
            ->where('status', 'pending')
            ->groupBy('comment_id')
            ->get();

        $pendingCount = Report::pending()->count();

        return view('admin.reports.index', compact('reports', 'groupedCounts', 'pendingCount'));
    }

    public function show(Report $report)
    {
        $report->load(['reporter', 'comment.user', 'comment.article', 'comment.reports.reporter', 'sanction']);

        $otherReports = Report::where('comment_id', $report->comment_id)
            ->where('id', '!=', $report->id)
            ->with('reporter')
            ->latest()
            ->get();

        return view('admin.reports.show', compact('report', 'otherReports'));
    }

    public function update(Request $request, Report $report)
    {
        $request->validate([
            'status' => 'required|in:pending,reviewed,actioned,dismissed,false_report',
        ]);

        $report->update([
            'status' => $request->status,
            'admin_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Trạng thái báo cáo đã được cập nhật.');
    }

    public function destroy(Report $report)
    {
        $report->delete();

        return redirect()->route('admin.reports.index')->with('success', 'Báo cáo đã được xóa.');
    }
}
