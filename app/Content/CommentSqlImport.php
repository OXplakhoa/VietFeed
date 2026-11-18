<?php

namespace App\Content;

use App\Models\Report;
use App\Moderation\ModerationCases;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Gate 9.5 slice 2 data move: copy legacy SQL `comments` into MongoDB and switch every moderation
 * reference to the comment's UUIDv7. Offline cutover only (maintenance mode, audit consumer drained,
 * after `moderation:import-sql`). Together with the 9b `SqlImport` this is the only reader of the SQL
 * `comments` table; the table is left in place, unused, and dropped in the final slice.
 *
 * Idempotent: every copy carries `legacy_sql_id` (unique index) so reruns skip it and reuse its id;
 * the remap filters only match legacy (integer or numeric-string) references, so a rerun after a
 * crash finishes the rest and a completed run changes nothing.
 */
final class CommentSqlImport
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    /** @return array<string, int> */
    public function run(): array
    {
        if (DB::connection()->getDriverName() === 'mongodb') {
            throw new LogicException('The default connection must point to the legacy SQL database.');
        }
        $counts = array_fill_keys(['sql_comments', 'comments_imported', 'comments_skipped', 'orphans_skipped', 'reports_remapped',
            'reports_deduplicated', 'cases_remapped', 'cases_merged', 'case_conflicts', 'case_events_remapped', 'sanction_events_remapped',
            'refs_unmapped', 'hidden_relinked', 'hidden_unlinked'], 0);

        $rows = DB::table('comments')->orderBy('id')->get();
        $counts['sql_comments'] = $rows->count();
        $ids = $this->idMap($rows);
        $sanctions = $this->legacySanctionIds();

        // 1. Parents before replies: a reply is inserted only once its parent exists in MongoDB, so an
        //    interrupted run never leaves a reply without its parent. Multi-level threads take several passes.
        $done = array_flip(array_map('intval', $this->collection('comments')->distinct('legacy_sql_id', ['legacy_sql_id' => ['$exists' => true]])));
        $pending = $rows->reject(function ($row) use ($done, &$counts) {
            if (isset($done[(int) $row->id])) {
                $counts['comments_skipped']++;

                return true;
            }

            return false;
        });
        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn ($row) => $row->parent_id === null || isset($done[(int) $row->parent_id]));
            if ($ready->isEmpty()) {
                break; // Parent missing from SQL (impossible under the old FK cascade): left out, counted.
            }
            foreach ($ready as $row) {
                if ($this->insert($row, $ids, $sanctions)) {
                    $counts['comments_imported']++;
                } else {
                    $counts['comments_skipped']++;
                }
                $done[(int) $row->id] = true;
            }
            $pending = $pending->diffKeys($ready);
        }
        $counts['orphans_skipped'] = $pending->count();

        // 2. Moderation references: legacy SQL ids → comment UUIDs (reports, cases incl. their unique
        //    comment_id, case and sanction audit events; the integer is kept as `legacy_comment_id`).
        $imported = $this->importedIds();
        foreach ($this->legacyReferences() as $legacy) {
            $uuid = $imported[$legacy] ?? null;
            if ($uuid === null) {
                $counts['refs_unmapped']++; // The SQL comment was deleted before the cutover: case shows "deleted".

                continue;
            }
            $match = ['$in' => [$legacy, (string) $legacy]];
            // One case per comment (unique comment_id): an int-keyed and a string-keyed case for the same
            // legacy comment (left by an earlier faulty run) are merged before the rename.
            [$merged, $deduplicated] = $this->mergeDuplicateCases($legacy, $uuid);
            $counts['cases_merged'] += $merged;
            $counts['reports_deduplicated'] += $deduplicated;
            foreach ($this->collection('moderation_cases')->find(['comment_id' => $match], ['projection' => ['_id' => 1]]) as $case) {
                try {
                    $counts['cases_remapped'] += $this->collection('moderation_cases')->updateOne(['_id' => $case['_id'], 'comment_id' => $match],
                        ['$set' => ['comment_id' => $uuid, 'legacy_comment_id' => $legacy]])->getModifiedCount();
                } catch (BulkWriteException $e) {
                    if ($e->getCode() !== 11000) {
                        throw $e;
                    }
                    $counts['case_conflicts']++; // A decision still in flight blocked the merge: rerun after moderation:repair.
                }
            }
            foreach ($this->collection('reports')->find(['comment_id' => $match]) as $report) {
                try {
                    $counts['reports_remapped'] += $this->collection('reports')->updateOne(['_id' => $report['_id'], 'comment_id' => $report['comment_id']],
                        ['$set' => ['comment_id' => $uuid]])->getModifiedCount();
                } catch (BulkWriteException $e) {
                    if ($e->getCode() !== 11000) {
                        throw $e;
                    }
                    $kept = $this->collection('reports')->findOne(['reporter_id' => $report['reporter_id'], 'comment_id' => $uuid], ['projection' => ['_id' => 1]]);
                    $counts['reports_deduplicated'] += $this->archiveDuplicateReport($report, $kept['_id'] ?? null);
                }
            }
            foreach (['moderation_cases' => 'case_events_remapped', 'sanctions' => 'sanction_events_remapped'] as $name => $key) {
                $counts[$key] += $this->collection($name)->updateMany(['events.comment_id' => $match],
                    ['$set' => ['events.$[e].comment_id' => $uuid, 'events.$[e].legacy_comment_id' => $legacy]],
                    ['arrayFilters' => [['e.comment_id' => $match]]])->getModifiedCount();
            }
        }

        // 3. Hide links that still hold a legacy integer sanction id (9b import not run on SQL before the copy).
        foreach ($this->collection('comments')->find(['hidden_by_sanction_id' => ['$regex' => '^[0-9]+$']], ['projection' => ['hidden_by_sanction_id' => 1]]) as $doc) {
            $uuid = $sanctions[(int) $doc['hidden_by_sanction_id']] ?? null;
            if ($uuid !== null) {
                $counts['hidden_relinked'] += $this->collection('comments')->updateOne(
                    ['_id' => $doc['_id'], 'hidden_by_sanction_id' => $doc['hidden_by_sanction_id']],
                    ['$set' => ['hidden_by_sanction_id' => $uuid]])->getModifiedCount();
            }
        }
        // Still hidden by a legacy sanction id with no MongoDB copy (also warned on /admin/processing).
        $counts['hidden_unlinked'] = $this->collection('comments')->countDocuments(['hidden_by_sanction_id' => ['$regex' => '^[0-9]+$']]);

        return $counts;
    }

    /**
     * Merge every other case of this legacy comment into one survivor (idempotent, crash-safe):
     * (1) the survivor absorbs the duplicate's events and decisions under a `merged_case_ids` guard,
     * (2) reports and sanctions move to the survivor, (3) the empty duplicate is deleted. Evidence is
     * kept: events are re-keyed to the survivor's audit partition (Cassandra rows of the old partition
     * stay as history), decisions are kept as settled history (round 0), pending reports join the
     * survivor's open round.
     */
    /** @return array{0: int, 1: int} cases merged, duplicate reports archived */
    private function mergeDuplicateCases(int $legacy, string $uuid): array
    {
        $variants = ['$in' => [$legacy, (string) $legacy, $uuid]];
        $cases = $this->collection('moderation_cases')->find(['comment_id' => $variants], ['sort' => ['created_at' => 1, '_id' => 1]])->toArray();
        if (count($cases) < 2) {
            return [0, 0];
        }
        $survivor = collect($cases)->first(fn ($c) => collect($cases)->contains(fn ($o) => in_array((string) $o['_id'], $c['merged_case_ids'] ?? [], true)))
            ?? collect($cases)->firstWhere('comment_id', $uuid)
            ?? collect($cases)->first(fn ($c) => is_int($c['comment_id']))
            ?? $cases[0];
        // One report per reporter and comment (unique index after the rename): keep the survivor's copy
        // (else the oldest) and archive the others BEFORE counters are merged.
        $archived = 0;
        $reports = collect($this->collection('reports')->find(['comment_id' => $variants], ['sort' => ['created_at' => 1, '_id' => 1]])->toArray());
        foreach ($reports->groupBy('reporter_id') as $group) {
            if ($group->count() < 2) {
                continue;
            }
            $kept = $group->first(fn ($r) => ($r['case_id'] ?? null) === $survivor['_id']) ?? $group->first();
            foreach ($group as $report) {
                if ($report['_id'] !== $kept['_id']) {
                    $archived += $this->archiveDuplicateReport($report, $kept['_id']);
                }
            }
        }
        $merged = 0;
        foreach ($cases as $duplicate) {
            if ($duplicate['_id'] === $survivor['_id']) {
                continue;
            }
            if (collect($duplicate['decisions'] ?? [])->contains('state', 'claimed') || collect($survivor['decisions'] ?? [])->contains('state', 'claimed')) {
                continue; // In-flight decision: leave both untouched (counted as a conflict by the rename).
            }
            $survivor = $this->absorb($survivor, $duplicate);
            $from = (string) $duplicate['_id'];
            $to = (string) $survivor['_id'];
            $this->collection('reports')->updateMany(['case_id' => $from], ['$set' => ['case_id' => $to]]);
            $this->collection('sanctions')->updateMany(['case_id' => $from], ['$set' => ['case_id' => $to]]);
            $this->collection('sanctions')->updateMany(['orphaned_from_case' => $from], ['$set' => ['orphaned_from_case' => $to]]);
            $this->collection('sanctions')->updateMany(['audit_case_id' => $from], ['$set' => ['audit_case_id' => $to, 'events.$[e].case_id' => $to]],
                ['arrayFilters' => [['e.case_id' => $from]]]);
            $this->collection('moderation_cases')->deleteOne(['_id' => $from]);
            $merged++;
        }

        return [$merged, $archived];
    }

    /** Step (1) of the merge: one guarded write on the survivor. Returns the survivor after the write. */
    private function absorb(array $survivor, array $duplicate): array
    {
        $from = (string) $duplicate['_id'];
        if (in_array($from, $survivor['merged_case_ids'] ?? [], true)) {
            return $survivor; // Absorbed by an interrupted earlier run: only steps (2)-(3) remain.
        }
        app(ModerationCases::class)->settle($from); // Materialise the duplicate's own decisions first.
        $to = (string) $survivor['_id'];
        $pending = collect($this->collection('reports')->find(['case_id' => $from, 'status' => 'pending'])->toArray());
        $pendingIds = $pending->pluck('_id')->map(fn ($id) => (string) $id)->all();
        $open = $pending->isEmpty() ? (int) $survivor['round'] : ($survivor['status'] === 'pending' ? (int) $survivor['round'] : (int) $survivor['round'] + 1);
        $events = array_map(function (array $event) use ($to, $pendingIds, $open) {
            $event['case_id'] = $to;
            $event['aggregate_id'] = $to;
            $event['round'] = $event['type'] === 'REPORTED' && in_array((string) $event['report_id'], $pendingIds, true) ? $open : 0;

            return $event;
        }, $duplicate['events'] ?? []);
        $decisions = array_map(fn (array $d) => ['round' => 0, 'from_round' => 0, 'settled' => true, 'merged_from' => $from] + $d,
            $duplicate['decisions'] ?? []);
        $survivorPending = $survivor['status'] === 'pending';
        $set = ['updated_at' => new UTCDateTime(now())];
        if ($pending->isNotEmpty()) {
            $starts = $pending->pluck('created_at')->filter()->all();
            if ($survivorPending && $survivor['round_started_at'] !== null) {
                $starts[] = $survivor['round_started_at'];
            }
            $set += [
                'status' => 'pending', 'round' => $open,
                'pending_count' => ($survivorPending ? (int) $survivor['pending_count'] : 0) + $pending->count(),
                'severity' => max($survivorPending ? (int) $survivor['severity'] : 0, (int) $pending->map(fn ($r) => Report::severityOf($r['reason'] ?? null))->max()),
                'round_started_at' => $starts ? min($starts) : null,
            ];
        }
        $this->collection('moderation_cases')->updateOne(
            ['_id' => $to, 'merged_case_ids' => ['$ne' => $from]],
            ['$set' => $set, '$push' => ['events' => ['$each' => $events], 'decisions' => ['$each' => $decisions]],
                '$inc' => ['version' => 1, 'reports_count' => $this->collection('reports')->countDocuments(['case_id' => $from])], '$addToSet' => ['merged_case_ids' => $from]],
        );

        return $this->collection('moderation_cases')->findOne(['_id' => $to]);
    }

    /**
     * The same reporter reported the same legacy comment under both id shapes (faulty earlier run).
     * Reports are evidence and never hard-deleted: the duplicate moves to `reports_duplicates`.
     */
    private function archiveDuplicateReport(array $report, ?string $keptId): int
    {
        $this->collection('reports_duplicates')->replaceOne(['_id' => $report['_id']],
            $report + ['duplicate_of' => $keptId, 'archived_at' => new UTCDateTime(now())], ['upsert' => true]);
        $this->collection('reports')->deleteOne(['_id' => $report['_id']]);

        return 1;
    }

    private function insert(object $row, array $ids, array $sanctions): bool
    {
        $hiddenBy = $row->hidden_by_sanction_id === null || $row->hidden_by_sanction_id === '' ? null : (string) $row->hidden_by_sanction_id;
        if ($hiddenBy !== null && ctype_digit($hiddenBy)) {
            $hiddenBy = $sanctions[(int) $hiddenBy] ?? $hiddenBy; // Unknown legacy id kept; relinked by a later rerun.
        }
        try {
            $this->collection('comments')->insertOne([
                '_id' => $ids[(int) $row->id], 'legacy_sql_id' => (int) $row->id,
                'user_id' => $row->user_id === null ? null : (string) $row->user_id,
                'article_id' => $row->article_id === null ? null : (string) $row->article_id,
                'parent_id' => $row->parent_id === null ? null : $ids[(int) $row->parent_id],
                'body' => (string) $row->body,
                'is_hidden' => (bool) ($row->is_hidden ?? false),
                'hidden_by_sanction_id' => $hiddenBy,
                'created_at' => $this->date($row->created_at), 'updated_at' => $this->date($row->updated_at),
            ]);

            return true;
        } catch (BulkWriteException $e) {
            if ($e->getCode() === 11000) {
                return false; // Copied concurrently or by an interrupted run: keep that copy.
            }
            throw $e;
        }
    }

    /** @return array<int, string> legacy SQL id => Mongo id (existing copies keep theirs) */
    private function idMap(Collection $rows): array
    {
        $map = $this->importedIds();
        foreach ($rows as $row) {
            $map[(int) $row->id] ??= (string) Str::uuid7();
        }

        return $map;
    }

    /** @return array<int, string> */
    private function importedIds(): array
    {
        $map = [];
        foreach ($this->collection('comments')->find(['legacy_sql_id' => ['$exists' => true]], ['projection' => ['legacy_sql_id' => 1]]) as $doc) {
            $map[(int) $doc['legacy_sql_id']] = (string) $doc['_id'];
        }

        return $map;
    }

    /** @return array<int, string> legacy SQL sanction id => Mongo sanction id */
    private function legacySanctionIds(): array
    {
        $map = [];
        foreach ($this->collection('sanctions')->find(['legacy_sql_id' => ['$exists' => true]], ['projection' => ['legacy_sql_id' => 1]]) as $doc) {
            $map[(int) $doc['legacy_sql_id']] = (string) $doc['_id'];
        }

        return $map;
    }

    /** @return list<int> legacy comment ids still referenced by moderation documents */
    private function legacyReferences(): array
    {
        $values = array_merge(
            $this->collection('reports')->distinct('comment_id'),
            $this->collection('moderation_cases')->distinct('comment_id'),
            $this->collection('moderation_cases')->distinct('events.comment_id'),
            $this->collection('sanctions')->distinct('events.comment_id'),
        );
        $legacy = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $legacy[(int) $value] = true;
            }
        }
        ksort($legacy);

        return array_keys($legacy);
    }

    private function date(mixed $value): UTCDateTime
    {
        return new UTCDateTime($value === null || $value === '' ? now() : CarbonImmutable::parse($value, config('app.timezone')));
    }

    private function collection(string $name): \MongoDB\Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
