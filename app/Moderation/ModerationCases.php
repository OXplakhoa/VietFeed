<?php

namespace App\Moderation;

use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\WriteException;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Per-comment case document on MongoDB: the single point of atomicity for moderation decisions.
 *
 * MongoDB runs standalone (no multi-document transactions), so every rule that Gate 9a held with
 * SQL row locks is a conditional update on this one document:
 *  - a decision is accepted only while `status = pending`, the `version` is unchanged and no other
 *    decision is in flight (`state = claimed`); the loser matches nothing and is refused;
 *  - report documents are updated afterwards by an idempotent `settle()`, which derives the set of
 *    covered reports from this document's own `REPORTED` events (rounds `from_round..round`);
 *  - a sanction decision is first *claimed*, then confirmed once the sanction document exists, or
 *    released if the insert failed. The sanction's existence decides a stale claim in `repair`.
 */
final class ModerationCases
{
    public const CLAIM_LEASE_SECONDS = 600;

    /** Lower bound for `moderation:repair --lease` so repair never releases a claim still in flight. */
    public const MIN_LEASE_SECONDS = 60;

    public const DECIDED = 'Báo cáo này đã được xử lý và không thể thay đổi.';

    public const BUSY = 'Hệ thống đang bận xử lý báo cáo này. Vui lòng thử lại.';

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(private ModerationQueue $queue, private ModerationAudit $audit) {}

    public function collection(string $name = 'moderation_cases'): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }

    public function find(string $caseId): ?array
    {
        return $this->collection()->findOne(['_id' => $caseId]);
    }

    public function findByComment(int $commentId): ?array
    {
        return $this->collection()->findOne(['comment_id' => $commentId]);
    }

    /** Upsert the case of the report's comment (unique on comment_id) and return its id. */
    public function ensureFor(Report $report): string
    {
        $commentId = (int) $report->comment_id;
        $now = new UTCDateTime(now());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $case = $this->collection()->findOneAndUpdate(['comment_id' => $commentId], ['$setOnInsert' => [
                    '_id' => (string) Str::uuid7(), 'comment_id' => $commentId, 'status' => 'none', 'round' => 0, 'version' => 0,
                    'pending_count' => 0, 'reports_count' => 0, 'severity' => 0, 'round_started_at' => null, 'last_reported_at' => null,
                    'comment_user_id' => $report->comment_user_id, 'comment_article_id' => $report->comment_article_id,
                    'decisions' => [], 'events' => [], 'created_at' => $now, 'updated_at' => $now,
                ]], ['upsert' => true, 'projection' => ['_id' => 1], 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);

                return (string) $case['_id'];
            } catch (CommandException|WriteException $e) {
                // Two first reports raced on the unique comment_id index: the other upsert won, read it.
                if ($e->getCode() !== 11000 || $attempt > 0) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('unreachable');
    }

    /**
     * Count the report on its case exactly once (guard: no REPORTED event for this report yet).
     * A report on a non-pending case starts a new round. Returns the case after the write, or null
     * when the report was already registered.
     */
    public function register(Report $report): ?array
    {
        if ($report->status !== 'pending') {
            // A decided report never reopens a case; it is recorded as history only.
            return $this->recordHistory($report);
        }
        $caseId = (string) $report->case_id;
        $severity = Report::severityOf($report->reason);
        $reportedAt = new UTCDateTime($report->created_at ?? now());
        $event = ModerationEvents::make('REPORTED', $caseId, 'case', $caseId, 0, (string) $report->reporter_id, null, [
            'report_id' => (string) $report->id, 'comment_id' => (int) $report->comment_id,
            'occurred_at' => ModerationEvents::time($report->created_at ?? now()),
        ]);
        $literal = ModerationEvents::literal($event);
        $literal['aggregate_version'] = ['$add' => [['$ifNull' => ['$version', 0]], 1]];
        $literal['case_version'] = $literal['aggregate_version'];
        $literal['round'] = '$round';
        $isNew = '$_moderation_new_round';

        $case = $this->collection()->findOneAndUpdate(
            ['_id' => $caseId, 'events.report_id' => ['$ne' => (string) $report->id]],
            [
                ['$set' => ['_moderation_new_round' => ['$ne' => ['$status', 'pending']]]],
                ['$set' => ['round' => ['$cond' => [$isNew, ['$add' => [['$ifNull' => ['$round', 0]], 1]], '$round']]]],
                ['$set' => [
                    'status' => ['$literal' => 'pending'],
                    'pending_count' => ['$cond' => [$isNew, 1, ['$add' => ['$pending_count', 1]]]],
                    'reports_count' => ['$add' => [['$ifNull' => ['$reports_count', 0]], 1]],
                    'severity' => ['$cond' => [$isNew, $severity, ['$max' => ['$severity', $severity]]]],
                    'round_started_at' => ['$cond' => [$isNew, $reportedAt, '$round_started_at']],
                    'last_reported_at' => $reportedAt,
                    'updated_at' => new UTCDateTime(now()),
                    'version' => ['$add' => [['$ifNull' => ['$version', 0]], 1]],
                    'events' => ['$concatArrays' => [['$ifNull' => ['$events', []]], [$literal]]],
                ]],
                ['$unset' => '_moderation_new_round'],
            ],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($case !== null) {
            $this->afterWrite($case, [$event['event_id']]);
        }

        return $case;
    }

    /** Record an already-decided report on its case (REPORTED, round 0) without counting it pending. */
    public function recordHistory(Report $report): ?array
    {
        $caseId = (string) $report->case_id;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $case = $this->find($caseId);
            if ($case === null || collect($case['events'] ?? [])->contains('report_id', (string) $report->id)) {
                return null;
            }
            $version = (int) $case['version'];
            $event = ModerationEvents::make('REPORTED', $caseId, 'case', $caseId, $version + 1, (string) $report->reporter_id, null, [
                'report_id' => (string) $report->id, 'comment_id' => (int) $report->comment_id, 'round' => 0,
                'occurred_at' => ModerationEvents::time($report->created_at ?? now()),
            ]);
            $after = $this->collection()->findOneAndUpdate(
                ['_id' => $caseId, 'version' => $version, 'events.report_id' => ['$ne' => (string) $report->id]],
                ['$push' => ['events' => $event], '$inc' => ['version' => 1, 'reports_count' => 1], '$set' => ['updated_at' => new UTCDateTime(now())]],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );
            if ($after !== null) {
                $this->afterWrite($after, [$event['event_id']]);

                return $after;
            }
        }

        return null;
    }

    /**
     * Decide the open round: dismissed / false_report directly, or `actioned` as a claim for sanction S.
     *
     * @param  array{sanction_id: string, hide: bool}|null  $claim
     * @return array{0: string, 1: array} op id and the case after the write
     *
     * @throws ModerationException
     */
    public function decide(string $caseId, string $status, string $adminId, ?string $note, ?array $claim = null): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $case = $this->find($caseId) ?? throw new ModerationException(self::DECIDED);
            $result = $this->attemptDecision($case, $status, $adminId, $note, $claim);
            if ($result !== null) {
                return $result;
            }
            // Version moved (a report was registered meanwhile, or another admin decided): re-read.
        }

        throw new ModerationException(self::BUSY);
    }

    /**
     * One conditional write against the case exactly as it was read. Returns null (nothing written)
     * when another writer changed the document since: the `version` filter no longer matches.
     *
     * @return array{0: string, 1: array}|null
     *
     * @throws ModerationException
     */
    public function attemptDecision(array $case, string $status, string $adminId, ?string $note, ?array $claim = null): ?array
    {
        $caseId = (string) $case['_id'];
        if ($this->claimedDecision($case) !== null) {
            throw new ModerationException(self::BUSY);
        }
        if ($case['status'] !== 'pending') {
            throw new ModerationException(self::DECIDED);
        }
        $opId = (string) Str::uuid7();
        $version = (int) $case['version'];
        $decision = [
            'op_id' => $opId, 'round' => (int) $case['round'], 'from_round' => $this->lastConfirmedRound($case) + 1,
            'status' => $status, 'state' => $claim ? 'claimed' : 'confirmed', 'admin_id' => $adminId, 'note' => $note,
            'decided_at' => new UTCDateTime(now()), 'settled' => false, 'sanction_id' => $claim['sanction_id'] ?? null,
            'hide' => (bool) ($claim['hide'] ?? false), 'hide_state' => ($claim['hide'] ?? false) ? 'requested' : null,
            'snapshot' => ['pending_count' => $case['pending_count'], 'severity' => $case['severity'], 'round_started_at' => $case['round_started_at']],
        ];
        $update = ['$set' => ['status' => $status, 'updated_at' => new UTCDateTime(now())], '$push' => ['decisions' => $decision], '$inc' => ['version' => 1]];
        $events = [];
        if ($claim === null) {
            $event = ModerationEvents::make($status === 'false_report' ? 'CASE_FALSE_REPORT' : 'CASE_DISMISSED', $caseId, 'case', $caseId,
                $version + 1, $adminId, $note, ['comment_id' => (int) $case['comment_id'], 'round' => (int) $case['round']]);
            $update['$push']['events'] = $event;
            $events[] = $event['event_id'];
        }
        $after = $this->collection()->findOneAndUpdate(
            ['_id' => $caseId, 'version' => $version, 'status' => 'pending'], $update,
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($after === null) {
            return null;
        }
        $this->afterWrite($after, $events);

        return [$opId, $after];
    }

    public function decision(string $caseId, string $opId): ?array
    {
        return collect($this->find($caseId)['decisions'] ?? [])->firstWhere('op_id', $opId);
    }

    public function referencesSanction(array $case, string $sanctionId): bool
    {
        return collect($case['decisions'] ?? [])->contains('sanction_id', $sanctionId);
    }

    /** A sanction whose case claim was lost: flag it on both documents so it is never silent. Idempotent. */
    public function markOrphan(string $caseId, string $sanctionId): bool
    {
        $this->collection()->updateOne(['_id' => $caseId], ['$addToSet' => ['orphaned_sanction_ids' => $sanctionId], '$set' => ['updated_at' => new UTCDateTime(now())]]);

        return $this->collection('sanctions')->updateOne(
            ['_id' => $sanctionId, 'orphaned_from_case' => ['$ne' => $caseId]],
            ['$set' => ['orphaned_from_case' => $caseId]],
        )->getModifiedCount() === 1;
    }

    /** The sanction exists: the claimed decision becomes final. Idempotent. */
    public function confirm(string $caseId, string $opId): bool
    {
        $after = $this->collection()->findOneAndUpdate(
            ['_id' => $caseId, 'decisions' => ['$elemMatch' => ['op_id' => $opId, 'state' => 'claimed']]],
            ['$set' => ['decisions.$.state' => 'confirmed', 'updated_at' => new UTCDateTime(now())], '$inc' => ['version' => 1]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($after !== null) {
            $this->afterWrite($after, []);
        }

        return $after !== null;
    }

    /** The sanction was not created: undo the claim; reports that arrived meanwhile are merged back. */
    public function release(string $caseId, string $opId): bool
    {
        $case = $this->find($caseId);
        $decision = collect($case['decisions'] ?? [])->firstWhere('op_id', $opId);
        if ($decision === null || $decision['state'] !== 'claimed') {
            return false;
        }
        $snapshot = $decision['snapshot'];
        $pending = ['$eq' => ['$status', 'pending']];
        $after = $this->collection()->findOneAndUpdate(
            ['_id' => $caseId, 'decisions' => ['$elemMatch' => ['op_id' => $opId, 'state' => 'claimed']]],
            [['$set' => [
                'pending_count' => ['$cond' => [$pending, ['$add' => ['$pending_count', $snapshot['pending_count']]], $snapshot['pending_count']]],
                'severity' => ['$cond' => [$pending, ['$max' => ['$severity', $snapshot['severity']]], $snapshot['severity']]],
                'round_started_at' => ['$cond' => [$pending, ['$min' => ['$round_started_at', $snapshot['round_started_at']]], $snapshot['round_started_at']]],
                'status' => ['$literal' => 'pending'],
                'decisions' => ['$filter' => ['input' => '$decisions', 'cond' => ['$ne' => ['$$this.op_id', ['$literal' => $opId]]]]],
                'version' => ['$add' => ['$version', 1]],
                'updated_at' => new UTCDateTime(now()),
            ]]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($after !== null) {
            $this->afterWrite($after, []);
        }

        return $after !== null;
    }

    /**
     * Materialise every confirmed, unsettled decision onto its report documents. Idempotent: only
     * still-pending reports of the decision's rounds are touched. Returns the count for $opId.
     */
    public function settle(string $caseId, ?string $opId = null): int
    {
        $case = $this->find($caseId);
        $count = 0;
        foreach ($case['decisions'] ?? [] as $decision) {
            if ($decision['state'] !== 'confirmed' || $decision['settled']) {
                continue;
            }
            $ids = collect($case['events'])->where('type', 'REPORTED')
                ->filter(fn ($e) => $e['round'] >= $decision['from_round'] && $e['round'] <= $decision['round'])
                ->pluck('report_id')->values()->all();
            $result = $this->collection('reports')->updateMany(['_id' => ['$in' => $ids], 'status' => 'pending'], ['$set' => [
                'status' => $decision['status'], 'admin_id' => $decision['admin_id'], 'decided_at' => $decision['decided_at'],
                'decision_note' => $decision['note'], 'sanction_id' => $decision['sanction_id'], 'updated_at' => new UTCDateTime(now()),
            ]]);
            $this->collection()->updateOne(['_id' => $caseId, 'decisions.op_id' => $decision['op_id']], ['$set' => ['decisions.$.settled' => true]]);
            if ($decision['op_id'] === $opId) {
                $count = $result->getModifiedCount();
            }
        }

        return $count;
    }

    /** Record the hide outcome once (the first writer wins the marker). */
    public function markHide(string $caseId, string $opId, string $state): bool
    {
        return $this->collection()->updateOne(
            ['_id' => $caseId, 'decisions' => ['$elemMatch' => ['op_id' => $opId, 'hide_state' => 'requested']]],
            ['$set' => ['decisions.$.hide_state' => $state, 'updated_at' => new UTCDateTime(now())]],
        )->getModifiedCount() === 1;
    }

    public function claimedDecision(array $case): ?array
    {
        return collect($case['decisions'] ?? [])->firstWhere('state', 'claimed');
    }

    private function lastConfirmedRound(array $case): int
    {
        return (int) collect($case['decisions'] ?? [])->where('state', 'confirmed')->max('round');
    }

    /** Cases with unfinished protocol steps (claimed, unsettled or hide requested). */
    public function unfinished(): iterable
    {
        return $this->collection()->find(['decisions' => ['$elemMatch' => ['$or' => [
            ['state' => 'claimed'], ['state' => 'confirmed', 'settled' => false], ['hide_state' => 'requested'],
        ]]]]);
    }

    /** Publish newly recorded events and refresh the queue projection; never fails the action. */
    public function afterWrite(array $case, array $eventIds): void
    {
        $events = collect($case['events'] ?? [])->whereIn('event_id', $eventIds)->values()->all();
        if ($events) {
            $this->audit->publish($events);
        }
        $this->queue->sync($case);
    }
}
