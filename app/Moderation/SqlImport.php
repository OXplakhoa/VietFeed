<?php

namespace App\Moderation;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

/**
 * Gate 9b data move: copy legacy SQL `reports`/`sanctions` into MongoDB. This is the only code that
 * still reads those SQL tables (they are dropped in Gate 9.5). Idempotent: every imported document
 * carries `legacy_sql_id` (unique index) and is inserted once with its backfill events, so a rerun or
 * a rerun after a crash skips what exists and finishes the rest.
 */
final class SqlImport
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private array $counts = [];

    public function __construct(private ModerationCases $cases, private ModerationQueue $queue, private ModerationAudit $audit) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $this->counts = array_fill_keys(['sql_reports', 'sql_sanctions', 'reports_imported', 'reports_skipped', 'sanctions_imported',
            'sanctions_skipped', 'cases_built', 'cases_merged', 'comments_relinked', 'events_published', 'queue_size'], 0);
        $sqlReports = DB::table('reports')->orderBy('id')->get();
        $sqlSanctions = DB::table('sanctions')->orderBy('id')->get();
        $this->counts['sql_reports'] = $sqlReports->count();
        $this->counts['sql_sanctions'] = $sqlSanctions->count();

        // 1. Stable ids: existing Mongo copies keep theirs, new rows get a UUIDv7 now.
        $reportIds = $this->idMap('reports', $sqlReports);
        $sanctionIds = $this->idMap('sanctions', $sqlSanctions);

        // 2. One case id per reported comment: the live case if any, else the id already used by an
        //    earlier (interrupted) import, else a new one.
        $caseIds = [];
        foreach ($sqlReports->groupBy('comment_id') as $commentId => $rows) {
            $existing = $this->cases->findByComment((int) $commentId);
            $previous = $this->collection('reports')->findOne(['legacy_sql_id' => ['$in' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all()], 'case_id' => ['$ne' => null]]);
            $caseIds[(int) $commentId] = $existing['_id'] ?? $previous['case_id'] ?? (string) Str::uuid7();
        }

        $hiddenBy = DB::table('comments')->whereNotNull('hidden_by_sanction_id')->get(['id', 'hidden_by_sanction_id'])
            ->groupBy(fn ($c) => (string) $c->hidden_by_sanction_id);
        $sqlReportsById = $sqlReports->keyBy('id');
        $published = [];

        // 3. Sanctions (raw inserts: the API-token hooks already ran when the SQL rows were written).
        foreach ($sqlSanctions as $row) {
            $id = $sanctionIds[$row->id];
            if ($this->collection('sanctions')->countDocuments(['legacy_sql_id' => (int) $row->id]) > 0) {
                $this->counts['sanctions_skipped']++;

                continue;
            }
            $report = $row->report_id ? $sqlReportsById[$row->report_id] ?? null : null;
            $caseId = $report ? $caseIds[(int) $report->comment_id] : null;
            $hidden = $hiddenBy[(string) $row->id] ?? collect();
            $doc = $this->sanctionDocument($row, $id, $caseId, $report ? $reportIds[$report->id] : null, $hidden);
            $this->collection('sanctions')->insertOne($doc);
            $published = array_merge($published, $doc['events']);
            $this->counts['sanctions_imported']++;
        }

        // 4. Reports.
        foreach ($sqlReports as $row) {
            if ($this->collection('reports')->countDocuments(['legacy_sql_id' => (int) $row->id]) > 0) {
                $this->counts['reports_skipped']++;

                continue;
            }
            $this->collection('reports')->insertOne([
                '_id' => $reportIds[$row->id], 'legacy_sql_id' => (int) $row->id, 'case_id' => $caseIds[(int) $row->comment_id],
                'reporter_id' => (string) $row->reporter_id, 'comment_id' => (int) $row->comment_id, 'reason' => $row->reason,
                'description' => $row->description, 'status' => $row->status,
                'comment_body' => $row->comment_body, 'comment_user_id' => $row->comment_user_id, 'comment_article_id' => $row->comment_article_id,
                'admin_id' => $row->admin_id, 'decided_at' => $this->date($row->decided_at), 'decision_note' => $row->decision_note,
                'sanction_id' => $row->sanction_id ? ($sanctionIds[$row->sanction_id] ?? null) : null,
                'created_at' => $this->date($row->created_at), 'updated_at' => $this->date($row->updated_at),
            ]);
            $this->counts['reports_imported']++;
        }

        // 5. Case documents from the Mongo copies of the legacy reports.
        foreach ($caseIds as $commentId => $caseId) {
            $published = array_merge($published, $this->buildCase($commentId, $caseId));
        }

        // 6. Comments hidden by a legacy integer sanction id now point at its UUID.
        foreach ($sanctionIds as $legacyId => $uuid) {
            $this->counts['comments_relinked'] += DB::table('comments')->where('hidden_by_sanction_id', (string) $legacyId)
                ->update(['hidden_by_sanction_id' => $uuid]);
        }

        // 7. Projections: audit events (idempotent by event_id) and the review queue.
        $this->counts['events_published'] = $published ? $this->audit->publish(array_map(fn ($e) => ModerationEvents::plain($e), $published)) : 0;
        $this->counts['queue_size'] = max(0, $this->queue->rebuild());

        return $this->counts;
    }

    /**
     * Cutover health check: legacy SQL rows that have no `legacy_sql_id` copy in MongoDB yet (their
     * sanctions are NOT enforced until `moderation:import-sql` runs).
     *
     * @return array{reports: int, sanctions: int}
     */
    public function unimported(): array
    {
        $missing = [];
        foreach (['reports', 'sanctions'] as $table) {
            if (! Schema::hasTable($table)) {
                $missing[$table] = 0;

                continue;
            }
            $imported = array_flip(array_map('intval', $this->collection($table)->distinct('legacy_sql_id', ['legacy_sql_id' => ['$exists' => true]])));
            $missing[$table] = DB::table($table)->pluck('id')->reject(fn ($id) => isset($imported[(int) $id]))->count();
        }

        return $missing;
    }

    /** @return array<int|string, string> legacy id => Mongo id */
    private function idMap(string $collection, Collection $rows): array
    {
        $existing = [];
        foreach ($this->collection($collection)->find(['legacy_sql_id' => ['$exists' => true]], ['projection' => ['legacy_sql_id' => 1]]) as $doc) {
            $existing[(int) $doc['legacy_sql_id']] = (string) $doc['_id'];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[$row->id] = $existing[(int) $row->id] ?? (string) Str::uuid7();
        }

        return $map;
    }

    private function sanctionDocument(object $row, string $id, ?string $caseId, ?string $reportId, Collection $hiddenComments): array
    {
        $partition = $caseId ?? $id;
        $created = $this->time($row->created_at);
        $events = [['SANCTION_ISSUED', $created, $row->admin_id, $row->type.': '.$row->reason, []]];
        foreach ($hiddenComments as $comment) {
            $events[] = ['COMMENT_HIDDEN', $created, $row->admin_id, null, ['comment_id' => (int) $comment->id]];
        }
        if ($row->appeal_status !== 'none') {
            $events[] = ['APPEAL_SUBMITTED', $this->time($row->appealed_at ?? $row->updated_at), $row->user_id, null, []];
        }
        if (in_array($row->appeal_status, ['approved', 'denied'], true)) {
            $events[] = [$row->appeal_status === 'approved' ? 'APPEAL_APPROVED' : 'APPEAL_DENIED', $this->time($row->appeal_decided_at ?? $row->updated_at),
                $row->appeal_decided_by, $row->appeal_decision_note, []];
        }
        if ($row->revoked_at) {
            $events[] = ['SANCTION_REVOKED', $this->time($row->revoked_at), $row->revoked_by, $row->revoke_note, []];
        }
        usort($events, fn ($a, $b) => $a[1] <=> $b[1]);
        $built = [];
        foreach ($events as $i => [$type, $at, $actor, $note, $refs]) {
            $built[] = ModerationEvents::make($type, $partition, 'sanction', $id, $i + 1, $actor === null ? null : (string) $actor, $note,
                $refs + ['sanction_id' => $id, 'report_id' => $reportId, 'occurred_at' => $at]);
        }

        return [
            '_id' => $id, 'legacy_sql_id' => (int) $row->id, 'user_id' => (string) $row->user_id, 'admin_id' => (string) $row->admin_id,
            'report_id' => $reportId, 'case_id' => $caseId, 'audit_case_id' => $partition, 'type' => $row->type, 'reason' => $row->reason,
            'expires_at' => $this->date($row->expires_at), 'is_active' => (bool) $row->is_active,
            'appeal_message' => $row->appeal_message, 'appeal_status' => $row->appeal_status ?? 'none',
            'revoked_at' => $this->date($row->revoked_at), 'revoked_by' => $row->revoked_by, 'revoke_note' => $row->revoke_note,
            'revoked_via' => $row->revoked_via, 'appealed_at' => $this->date($row->appealed_at),
            'appeal_decided_at' => $this->date($row->appeal_decided_at), 'appeal_decided_by' => $row->appeal_decided_by,
            'appeal_decision_note' => $row->appeal_decision_note,
            'version' => count($built), 'events' => $built,
            'created_at' => $this->date($row->created_at), 'updated_at' => $this->date($row->updated_at),
        ];
    }

    /** Build (or merge into a live) case document from the imported reports of one comment. */
    private function buildCase(int $commentId, string $caseId): array
    {
        $reports = collect($this->collection('reports')->find(['comment_id' => $commentId, 'legacy_sql_id' => ['$exists' => true]], ['sort' => ['created_at' => 1, '_id' => 1]])->toArray());
        $existing = $this->cases->find($caseId) ?? $this->cases->findByComment($commentId);
        if ($existing !== null) {
            return $this->mergeIntoCase($existing, $reports);
        }

        // One confirmed, settled decision per distinct legacy outcome (chronological); pending reports form the open round.
        $decided = $reports->where('status', '!=', 'pending')
            ->groupBy(fn ($r) => implode('|', [$r['status'], $r['sanction_id'] ?? '', $r['admin_id'] ?? '', $r['decided_at'] ? (string) $r['decided_at'] : '']))
            ->sortBy(fn ($group) => $group->first()['decided_at'] ? (int) (string) $group->first()['decided_at'] : 0)->values();
        $pending = $reports->where('status', 'pending')->values();
        $events = [];
        $decisions = [];
        foreach ($decided as $i => $group) {
            $round = $i + 1;
            $first = $group->first();
            foreach ($group as $report) {
                $events[] = $this->reportedEvent($caseId, $report, $round);
            }
            $at = $first['decided_at'] ?? $first['updated_at'];
            $decisions[] = [
                'op_id' => (string) Str::uuid7(), 'round' => $round, 'from_round' => $round, 'status' => $first['status'], 'state' => 'confirmed',
                'admin_id' => $first['admin_id'], 'note' => $first['decision_note'] ?? null, 'decided_at' => $at, 'settled' => true,
                'sanction_id' => $first['sanction_id'] ?? null, 'hide' => false, 'hide_state' => null, 'snapshot' => null,
            ];
            if (in_array($first['status'], ['dismissed', 'false_report'], true)) {
                $events[] = ModerationEvents::make($first['status'] === 'false_report' ? 'CASE_FALSE_REPORT' : 'CASE_DISMISSED', $caseId, 'case', $caseId, 0,
                    $first['admin_id'], $first['decision_note'] ?? null, ['comment_id' => $commentId, 'round' => $round, 'occurred_at' => $this->time($at)]);
            }
        }
        $openRound = $decided->count() + 1;
        foreach ($pending as $report) {
            $events[] = $this->reportedEvent($caseId, $report, $openRound);
        }
        usort($events, fn ($a, $b) => [$a['occurred_at'], $a['event_id']] <=> [$b['occurred_at'], $b['event_id']]);
        foreach ($events as $i => &$event) {
            $event['aggregate_version'] = $event['case_version'] = $i + 1;
        }
        unset($event);
        $first = $reports->first();
        $doc = [
            '_id' => $caseId, 'comment_id' => $commentId, 'imported' => true,
            'status' => $pending->isNotEmpty() ? 'pending' : ($decisions ? end($decisions)['status'] : 'none'),
            'round' => $pending->isNotEmpty() ? $openRound : $decided->count(), 'version' => count($events),
            'pending_count' => $pending->count(), 'reports_count' => $reports->count(),
            'severity' => (int) $pending->map(fn ($r) => Report::severityOf($r['reason']))->max(),
            'round_started_at' => $pending->first()['created_at'] ?? null, 'last_reported_at' => $reports->last()['created_at'] ?? null,
            'comment_user_id' => $first['comment_user_id'] ?? null, 'comment_article_id' => $first['comment_article_id'] ?? null,
            'decisions' => $decisions, 'events' => $events, 'created_at' => $first['created_at'] ?? new UTCDateTime(now()), 'updated_at' => new UTCDateTime(now()),
        ];
        $this->collection('moderation_cases')->insertOne($doc);
        $this->counts['cases_built']++;

        return $events;
    }

    /** A live case already exists for this comment: register pending legacy reports, record decided ones as history. */
    private function mergeIntoCase(array $case, Collection $reports): array
    {
        $known = collect($case['events'] ?? [])->pluck('report_id')->filter()->all();
        $events = [];
        $merged = false;
        foreach ($reports as $report) {
            if (in_array((string) $report['_id'], $known, true)) {
                continue;
            }
            $this->collection('reports')->updateOne(['_id' => $report['_id']], ['$set' => ['case_id' => (string) $case['_id']]]);
            if ($report['status'] === 'pending') {
                // Normal registration (counts it, publishes its REPORTED event, syncs the queue).
                $this->cases->register(Report::find($report['_id']));
                $merged = true;

                continue;
            }
            // Decided legacy report: history only (REPORTED, round 0), published by recordHistory().
            if ($this->cases->recordHistory(Report::find($report['_id'])) !== null) {
                $merged = true;
            }
        }
        if ($merged) {
            $this->counts['cases_merged']++;
        }

        return $events;
    }

    private function reportedEvent(string $caseId, array $report, int $round): array
    {
        return ModerationEvents::make('REPORTED', $caseId, 'case', $caseId, 0, (string) $report['reporter_id'], null, [
            'report_id' => (string) $report['_id'], 'comment_id' => (int) $report['comment_id'], 'round' => $round,
            'occurred_at' => $this->time($report['created_at']),
        ]);
    }

    private function date(mixed $value): ?UTCDateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        return new UTCDateTime(CarbonImmutable::parse($value, config('app.timezone')));
    }

    private function time(mixed $value): string
    {
        $date = $value instanceof UTCDateTime ? $value->toDateTime() : CarbonImmutable::parse($value ?? 'now', config('app.timezone'));

        return ModerationEvents::time($date);
    }

    private function collection(string $name): \MongoDB\Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
