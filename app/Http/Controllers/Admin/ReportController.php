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
            'view' => 'nullable|in:queue',
        ]);

        $queueSource = null;
        if ($request->input('view') === 'queue') {
            // Gate 9b: pending cases by priority from the Redis review queue (MongoDB fallback).
            ['cases' => $cases, 'source' => $queueSource] = $queries->priorityQueue();
            $cases->withQueryString();
        } else {
            $cases = $queries->inbox($request->input('status'), $request->input('reason'), $request->input('q'))->withQueryString();
        }
        $pendingCount = $queries->pendingCaseCount();

        return view('admin.reports.index', compact('cases', 'pendingCount', 'queueSource'));
    }

    public function show(string $report, ModerationQueries $queries)
    {
        $found = $queries->findReport($report);
        abort_if($found === null, 404);

        return view('admin.reports.show', $queries->caseFor($found));
    }

    /** Close a pending case without a sanction (dismissed / false_report). */
    public function update(Request $request, string $report, ModerationService $moderation, ModerationQueries $queries)
    {
        $found = $queries->findReport($report);
        abort_if($found === null, 404);
        $data = $request->validate([
            'status' => 'required|in:dismissed,false_report',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $moderation->resolveCase($request->user(), $found, $data['status'], $data['note'] ?? null);
        } catch (ModerationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xử lý báo cáo.');
    }
}
