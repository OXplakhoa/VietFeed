<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Moderation\ModerationException;
use App\Moderation\ModerationQueries;
use App\Moderation\ModerationService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, ModerationQueries $queries)
    {
        $request->validate([
            'status' => 'nullable|in:'.implode(',', Report::STATUSES),
            'reason' => 'nullable|in:'.implode(',', Report::REASONS),
            'q' => 'nullable|string|max:200',
        ]);

        $cases = $queries->inbox($request->input('status'), $request->input('reason'), $request->input('q'))->withQueryString();
        $pendingCount = $queries->pendingReportCount();

        return view('admin.reports.index', compact('cases', 'pendingCount'));
    }

    public function show(Report $report, ModerationQueries $queries)
    {
        return view('admin.reports.show', $queries->caseFor($report));
    }

    /** Close a pending case without a sanction (dismissed / false_report). */
    public function update(Request $request, Report $report, ModerationService $moderation)
    {
        $data = $request->validate([
            'status' => 'required|in:dismissed,false_report',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $moderation->resolveCase($request->user(), $report, $data['status'], $data['note'] ?? null);
        } catch (ModerationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xử lý báo cáo.');
    }
}
