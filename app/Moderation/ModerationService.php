<?php

namespace App\Moderation;

use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

/**
 * Moderation commands (report, decide, sanction, appeal, revoke).
 *
 * Gate 9b: reports, sanctions and the per-comment case document live on MongoDB (standalone, no
 * transactions). Each rule is one conditional single-document write; follow-up writes (report
 * settlement, comment hide/unhide, audit publish, queue sync) are idempotent and converge through
 * {@see repair()}. Gate 9.5: comments are MongoDB documents too; hide/unhide are single-document
 * conditional updates on the comment. Evidence is never hard-deleted.
 */
class ModerationService
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        private ModerationCases $cases,
        private ModerationQueue $queue,
        private ModerationAudit $audit,
        private SanctionSnapshot $snapshot,
    ) {}

    /** @throws ModerationException */
    public function reportComment(User $reporter, ?Comment $comment, string $reason, ?string $description): Report
    {
        if ($comment === null) {
            throw new ModerationException('Bình luận này không còn tồn tại.');
        }
        if ($reporter->isAdmin()) {
            throw new ModerationException('Quản trị viên không cần báo cáo.');
        }
        if (! $reporter->hasVerifiedEmail()) {
            throw new ModerationException('Vui lòng xác minh email trước khi báo cáo bình luận.');
        }
        if ($reporter->isBanned()) {
            throw new ModerationException('Tài khoản đang bị hạn chế nên bạn không thể gửi báo cáo.');
        }
        if ((string) $comment->user_id === (string) $reporter->id) {
            throw new ModerationException('Bạn không thể báo cáo bình luận của chính mình.');
        }
        if ($comment->is_hidden) {
            throw new ModerationException('Bình luận này đã bị ẩn nên không thể báo cáo.');
        }

        $duplicate = new ModerationException('Bạn đã báo cáo bình luận này rồi.');
        if (Report::where('reporter_id', (string) $reporter->id)->where('comment_id', (string) $comment->id)->exists()) {
            throw $duplicate;
        }

        try {
            // Unique {reporter_id, comment_id} index: a concurrent duplicate fails here with no record.
            return Report::create([
                'reporter_id' => (string) $reporter->id,
                'comment_id' => (string) $comment->id,
                'reason' => $reason,
                'description' => $description,
                'status' => 'pending',
                'comment_body' => $comment->body,
                'comment_user_id' => (string) $comment->user_id,
                'comment_article_id' => (string) $comment->article_id,
            ]);
        } catch (BulkWriteException $e) {
            if ($e->getCode() === 11000) {
                throw $duplicate;
            }
            throw $e;
        }
    }

    /**
     * Close a pending case as dismissed / false_report. Every pending report on the same comment is resolved.
     *
     * @return int number of reports resolved
     *
     * @throws ModerationException
     */
    public function resolveCase(User $admin, Report $report, string $status, ?string $note): int
    {
        if (! in_array($status, ['dismissed', 'false_report'], true)) {
            throw new ModerationException('Trạng thái không hợp lệ.');
        }
        $report = $this->prepare($report);
        if ($report->status !== 'pending') {
            throw new ModerationException(ModerationCases::DECIDED);
        }
        [$opId] = $this->cases->decide((string) $report->case_id, $status, (string) $admin->id, $note);

        return $this->cases->settle((string) $report->case_id, $opId);
    }

    /**
     * Issue a sanction, optionally from a case and optionally hiding the reported comment.
     *
     * @throws ModerationException
     */
    public function issueSanction(User $admin, User $target, string $type, string $reason, ?int $durationDays, ?string $reportId = null, bool $hideComment = false, ?string $note = null): Sanction
    {
        if ($reportId === null && $hideComment) {
            throw new ModerationException('Chỉ có thể ẩn bình luận khi xử lý từ một báo cáo.');
        }
        $expiresAt = in_array($type, ['mute', 'temporary_ban'], true) ? now()->addDays($durationDays ?? 7) : null;
        $sanctionId = (string) Str::uuid7();
        $attributes = [
            'user_id' => (string) $target->id, 'admin_id' => (string) $admin->id, 'type' => $type, 'reason' => $reason,
            'expires_at' => $expiresAt, 'is_active' => true, 'report_id' => null, 'case_id' => null,
        ];

        if ($reportId === null) {
            return $this->createSanction($sanctionId, $attributes, null, $admin);
        }

        $report = Report::find($reportId) ?? throw new ModerationException('Không tìm thấy báo cáo.');
        $report = $this->prepare($report);
        if ($report->status !== 'pending') {
            throw new ModerationException(ModerationCases::DECIDED);
        }
        // Reports filed before the snapshot existed fall back to the live comment.
        $authorId = $report->comment_user_id ?? Comment::find($report->comment_id)?->user_id;
        if ($authorId !== null && (string) $authorId !== (string) $target->id) {
            throw new ModerationException('Người bị xử lý phải là tác giả của bình luận bị báo cáo.');
        }

        $caseId = (string) $report->case_id;
        // 1. Claim the open round on the case document (refused if decided or another decision is in flight).
        [$opId, $claimed] = $this->cases->decide($caseId, 'actioned', (string) $admin->id, $note, ['sanction_id' => $sanctionId, 'hide' => $hideComment]);
        // 2. Insert the sanction (token hooks run here). The sanction's existence decides the claim.
        try {
            $sanction = $this->createSanction($sanctionId, ['report_id' => (string) $report->id, 'case_id' => $caseId] + $attributes, $report, $admin, (int) $claimed['version']);
        } catch (Throwable $error) {
            try {
                $sanction = Sanction::find($sanctionId);
                if ($sanction === null) {
                    $this->cases->release($caseId, $opId);
                }
            } catch (Throwable) {
                $sanction = null; // A stale claim is released by repair after the lease.
            }
            if ($sanction === null) {
                throw $error;
            }
        }
        // 3-5. Confirm (or re-claim / flag as orphan), settle reports, hide the comment if chosen.
        $opId = $this->confirmOrReclaim($caseId, $opId, $sanctionId, (string) $admin->id, $note, $hideComment);
        if ($opId !== null) {
            $this->finishDecision($caseId, $opId);
        }

        return $sanction;
    }

    /**
     * The sanction exists. Normally its claim is confirmed here. If this request stalled past the lease
     * and repair released the claim, re-claim the still-pending case for this sanction; when the case
     * was decided otherwise meanwhile, flag the sanction as orphaned on both documents (never silent).
     */
    private function confirmOrReclaim(string $caseId, string $opId, string $sanctionId, string $adminId, ?string $note, bool $hide): ?string
    {
        if ($this->cases->confirm($caseId, $opId) || ($this->cases->decision($caseId, $opId)['state'] ?? null) === 'confirmed') {
            return $opId;
        }
        try {
            [$newOpId] = $this->cases->decide($caseId, 'actioned', $adminId, $note, ['sanction_id' => $sanctionId, 'hide' => $hide]);
            $this->cases->confirm($caseId, $newOpId);

            return $newOpId;
        } catch (ModerationException) {
            $this->cases->markOrphan($caseId, $sanctionId);
            Log::warning('Moderation sanction orphaned from its case', ['case_id' => $caseId, 'sanction_id' => $sanctionId]);

            return null;
        }
    }

    /** @throws ModerationException */
    public function submitAppeal(User $user, Sanction $sanction, string $message): void
    {
        if ((string) $sanction->user_id !== (string) $user->id) {
            throw new ModerationException('Bạn không thể kháng cáo biện pháp xử lý của người khác.', 403);
        }
        if ($sanction->appeal_status !== 'none') {
            throw new ModerationException('Bạn chỉ có thể kháng cáo một lần.', 403);
        }
        if (! $sanction->isCurrentlyActive()) {
            throw new ModerationException('Biện pháp xử lý này không còn hiệu lực nên không thể kháng cáo.');
        }

        // Conditional update: a concurrent second submit matches nothing and never overwrites the first message.
        $result = $this->sanctionWrite((string) $sanction->id,
            ['user_id' => (string) $user->id, 'appeal_status' => 'none', 'is_active' => true, 'revoked_at' => null],
            ['appeal_message' => $message, 'appeal_status' => 'pending', 'appealed_at' => new UTCDateTime(now())],
            [['APPEAL_SUBMITTED', (string) $user->id, null, []]]);
        if ($result === null) {
            throw new ModerationException('Bạn chỉ có thể kháng cáo một lần.', 403);
        }
        $this->queue->refreshCounters();
    }

    /** @throws ModerationException */
    public function decideAppeal(User $admin, Sanction $sanction, string $decision, ?string $note): Sanction
    {
        $id = (string) $sanction->id;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = Sanction::find($id) ?? throw new ModerationException('Không tìm thấy biện pháp xử lý.');
            if ($current->appeal_status !== 'pending') {
                throw new ModerationException('Chỉ có thể quyết định các kháng cáo đang chờ duyệt.');
            }
            $revoke = $decision === 'approved' && $current->revoked_at === null;
            $set = [
                'appeal_status' => $decision, 'appeal_decided_at' => new UTCDateTime(now()),
                'appeal_decided_by' => (string) $admin->id, 'appeal_decision_note' => $note,
            ];
            $events = [[$decision === 'approved' ? 'APPEAL_APPROVED' : 'APPEAL_DENIED', (string) $admin->id, $note, []]];
            $condition = ['appeal_status' => 'pending'];
            if ($decision === 'approved') {
                $condition['revoked_at'] = $revoke ? null : ['$ne' => null];
            }
            if ($revoke) {
                $set += $this->revocation($admin, $note, 'appeal');
                $events = array_merge($events, $this->revokeEvents($id, $admin, $note));
            }
            $before = $this->sanctionWrite($id, $condition, $set, $events, retry: false);
            if ($before === null) {
                continue; // State moved between read and write: re-read and re-check.
            }
            if ($revoke) {
                $this->afterLift($before);
            }
            $this->queue->refreshCounters();

            return Sanction::find($id);
        }

        throw new ModerationException(ModerationCases::BUSY);
    }

    /**
     * Revoke (replaces delete): the sanction stays in history with who/when/why.
     * Undoing the sanction also unhides every comment this very sanction hid.
     *
     * @throws ModerationException
     */
    public function revokeSanction(User $admin, Sanction $sanction, ?string $note): Sanction
    {
        $id = (string) $sanction->id;
        $before = $this->sanctionWrite($id, ['revoked_at' => null, 'is_active' => true], $this->revocation($admin, $note, 'admin'),
            $this->revokeEvents($id, $admin, $note));
        if ($before === null) {
            throw new ModerationException('Biện pháp xử lý này đã được thu hồi hoặc không còn hiệu lực.');
        }
        $this->afterLift($before);

        return Sanction::find($id);
    }

    /**
     * Finish every interrupted protocol step (idempotent). Stale claims older than the lease are
     * confirmed when their sanction exists and released otherwise.
     *
     * @return array<string, int>
     */
    public function repair(int $leaseSeconds = ModerationCases::CLAIM_LEASE_SECONDS): array
    {
        // Never race a request that may still be between its claim and its sanction insert.
        $leaseSeconds = max(ModerationCases::MIN_LEASE_SECONDS, $leaseSeconds);
        $done = ['registered' => $this->registerMissingReports(), 'confirmed' => 0, 'released' => 0, 'finished' => 0, 'unhidden' => 0,
            'unhidden_revoked' => 0, 'orphaned_new' => 0, 'orphaned_total' => 0];
        foreach ($this->cases->unfinished() as $case) {
            foreach ($this->repairCase($case, $leaseSeconds) as $key => $n) {
                $done[$key] += $n;
            }
        }
        foreach (Sanction::where('unhide_pending', true)->get(['_id']) as $sanction) {
            $this->finishUnhide((string) $sanction->id);
            $done['unhidden']++;
        }
        $done['unhidden_revoked'] = $this->sweepCommentsOfLiftedSanctions();
        $done['orphaned_new'] = $this->flagOrphanedSanctions();
        $done['orphaned_total'] = Sanction::whereNotNull('orphaned_from_case')->count();
        $this->queue->rebuildQuietly();

        return $done;
    }

    /** @return array<string, int> */
    private function repairCase(array $case, int $leaseSeconds): array
    {
        $done = ['confirmed' => 0, 'released' => 0, 'finished' => 0];
        $caseId = (string) $case['_id'];
        foreach ($case['decisions'] as $decision) {
            if ($decision['state'] === 'claimed') {
                $age = now()->getTimestamp() - intdiv((int) (string) $decision['decided_at'], 1000);
                if ($age < $leaseSeconds) {
                    continue; // Possibly still running; never race the original request.
                }
                if (Sanction::find($decision['sanction_id']) !== null) {
                    $this->finishDecision($caseId, $decision['op_id']);
                    $done['confirmed']++;
                } elseif ($this->cases->release($caseId, $decision['op_id'])) {
                    $done['released']++;
                }
            } elseif (! $decision['settled'] || $decision['hide_state'] === 'requested') {
                $this->finishDecision($caseId, $decision['op_id']);
                $done['finished']++;
            }
        }

        return $done;
    }

    /**
     * Comments still hidden by a revoked/inactive sanction (crash between a hide and its revoke
     * re-check): unhide them and record COMMENT_UNHIDDEN.
     */
    private function sweepCommentsOfLiftedSanctions(): int
    {
        $count = 0;
        collect($this->collection('comments')->distinct('hidden_by_sanction_id', ['hidden_by_sanction_id' => ['$ne' => null]]))
            ->chunk(500)->each(function ($ids) use (&$count) {
                $lifted = Sanction::whereIn('_id', $ids->map(fn ($id) => (string) $id)->values()->all())
                    ->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('is_active', false))->get(['_id', 'revoked_by', 'admin_id']);
                foreach ($lifted as $sanction) {
                    $count += $this->unhideComments((string) $sanction->id, $sanction->revoked_by ?? $sanction->admin_id);
                }
            });

        return $count;
    }

    /** Case-linked sanctions that no decision of their case references (lost claim). */
    private function flagOrphanedSanctions(): int
    {
        $count = 0;
        $cutoff = now()->subSeconds(ModerationCases::MIN_LEASE_SECONDS);
        foreach (Sanction::whereNotNull('case_id')->whereNull('orphaned_from_case')->where('created_at', '<', $cutoff)->get(['_id', 'case_id']) as $sanction) {
            $case = $this->cases->find((string) $sanction->case_id);
            if ($case !== null && ! $this->cases->referencesSanction($case, (string) $sanction->id)
                && $this->cases->markOrphan((string) $sanction->case_id, (string) $sanction->id)) {
                $count++;
            }
        }

        return $count;
    }

    /** Reload the report, attach/register it on its case, finish earlier decisions, and return it fresh. */
    private function prepare(Report $report): Report
    {
        $report = Report::find($report->id) ?? throw new ModerationException('Không tìm thấy báo cáo.');
        if (! $report->case_id) {
            $report->case_id = $this->cases->ensureFor($report);
            Report::where('_id', $report->id)->update(['case_id' => $report->case_id]);
        }
        $case = $this->cases->find((string) $report->case_id);
        if (! collect($case['events'] ?? [])->contains('report_id', (string) $report->id)) {
            $this->cases->register($report);
            $case = $this->cases->find((string) $report->case_id);
        }
        $this->repairCase($case, ModerationCases::CLAIM_LEASE_SECONDS);
        $this->cases->settle((string) $report->case_id);

        return Report::find($report->id);
    }

    private function finishDecision(string $caseId, string $opId): void
    {
        $this->cases->confirm($caseId, $opId);
        $this->cases->settle($caseId);
        $this->applyHide($caseId, $opId);
    }

    private function createSanction(string $id, array $attributes, ?Report $report, User $admin, ?int $caseVersion = null): Sanction
    {
        $auditCaseId = $attributes['case_id'] ?? $id;
        $event = ModerationEvents::make('SANCTION_ISSUED', $auditCaseId, 'sanction', $id, 1, (string) $admin->id,
            $attributes['type'].': '.$attributes['reason'], [
                'sanction_id' => $id, 'report_id' => $report?->id === null ? null : (string) $report->id,
                'comment_id' => $report?->comment_id, 'case_version' => $caseVersion,
            ]);
        $sanction = new Sanction($attributes);
        $sanction->forceFill(['_id' => $id, 'audit_case_id' => $auditCaseId, 'version' => 1, 'events' => [$event]]);
        $sanction->save();
        $this->audit->publish([$event]);

        return $sanction;
    }

    /** The only code path that sets is_hidden = true (owner decision 1, R4). */
    private function applyHide(string $caseId, string $opId): void
    {
        $case = $this->cases->find($caseId);
        $decision = collect($case['decisions'] ?? [])->firstWhere('op_id', $opId);
        if ($decision === null || $decision['state'] !== 'confirmed' || $decision['hide_state'] !== 'requested') {
            return;
        }
        $sanctionId = (string) $decision['sanction_id'];
        $lifted = fn (?Sanction $s) => $s === null || $s->revoked_at !== null || ! $s->is_active;
        if ($lifted(Sanction::find($sanctionId))) {
            // Also covers a crash after the SQL hide below and before its re-check.
            $this->unhideComments($sanctionId, $decision['admin_id']);
            $this->cases->markHide($caseId, $opId, 'skipped');

            return;
        }
        // Single-document conditional update: only a currently visible comment is taken over; one already
        // hidden by another sanction keeps its owner (owner decision 1, R4).
        $commentId = (string) $case['comment_id'];
        $this->collection('comments')->updateOne(['_id' => $commentId, 'is_hidden' => ['$ne' => true]],
            ['$set' => ['is_hidden' => true, 'hidden_by_sanction_id' => $sanctionId, 'updated_at' => new UTCDateTime(now())]]);
        $comment = $this->collection('comments')->findOne(['_id' => $commentId], ['projection' => ['is_hidden' => 1, 'hidden_by_sanction_id' => 1]]);
        $applied = $comment !== null && ($comment['is_hidden'] ?? false) === true && (string) ($comment['hidden_by_sanction_id'] ?? '') === $sanctionId;
        // Re-check BEFORE recording the outcome: a revoke that ran between our check and the hide must win.
        if ($applied && $lifted(Sanction::find($sanctionId))) {
            $this->unhideComments($sanctionId, $decision['admin_id']);
            $this->cases->markHide($caseId, $opId, 'skipped');

            return;
        }
        if ($this->cases->markHide($caseId, $opId, $applied ? 'applied' : 'skipped') && $applied) {
            $this->sanctionWrite($sanctionId, [], [], [['COMMENT_HIDDEN', $decision['admin_id'], null, ['comment_id' => $commentId]]]);
        }
    }

    /** After a revoke/approved appeal: unhide, then the post-write token hook (same order as 9a). */
    private function afterLift(array $before): void
    {
        $id = (string) $before['_id'];
        $this->finishUnhide($id);
        $model = (new Sanction)->newFromBuilder($before);
        $this->snapshot->forget((string) $model->user_id);
        if ($model->isEffectiveBan($model->getAttributes())) {
            $model->afterBanTransition(-1);
        }
    }

    /** Apply the recorded unhide decision (idempotent: only comments still hidden by this sanction) and clear the marker. */
    private function finishUnhide(string $sanctionId): void
    {
        $this->collection('comments')->updateMany(['hidden_by_sanction_id' => $sanctionId], $this->unhideUpdate());
        $this->collection()->updateOne(['_id' => $sanctionId], ['$unset' => ['unhide_pending' => '']]);
    }

    /** Unhide every comment hidden by this sanction; one COMMENT_UNHIDDEN per comment actually changed. */
    private function unhideComments(string $sanctionId, ?string $actorId): int
    {
        $count = 0;
        foreach ($this->commentsHiddenBy($sanctionId) as $commentId) {
            // Conditional on this very sanction: a comment re-hidden by another sanction is never unhidden.
            $changed = $this->collection('comments')->updateOne(['_id' => $commentId, 'hidden_by_sanction_id' => $sanctionId], $this->unhideUpdate())->getModifiedCount();
            if ($changed > 0) {
                $this->sanctionWrite($sanctionId, [], [], [['COMMENT_UNHIDDEN', $actorId, null, ['comment_id' => $commentId]]]);
                $count++;
            }
        }

        return $count;
    }

    /** COMMENT_UNHIDDEN decisions recorded in the same write as the revoke (applied to the comments right after). */
    private function revokeEvents(string $sanctionId, User $admin, ?string $note): array
    {
        $events = [['SANCTION_REVOKED', (string) $admin->id, $note, []]];
        foreach ($this->commentsHiddenBy($sanctionId) as $commentId) {
            $events[] = ['COMMENT_UNHIDDEN', (string) $admin->id, null, ['comment_id' => $commentId]];
        }

        return $events;
    }

    /** @return list<string> ids of the comments currently hidden by this sanction */
    private function commentsHiddenBy(string $sanctionId): array
    {
        return array_map(fn ($doc) => (string) $doc['_id'],
            $this->collection('comments')->find(['hidden_by_sanction_id' => $sanctionId], ['projection' => ['_id' => 1]])->toArray());
    }

    private function unhideUpdate(): array
    {
        return ['$set' => ['is_hidden' => false, 'hidden_by_sanction_id' => null, 'updated_at' => new UTCDateTime(now())]];
    }

    private function revocation(User $admin, ?string $note, string $via): array
    {
        return [
            'is_active' => false,
            'revoked_at' => new UTCDateTime(now()),
            'revoked_by' => (string) $admin->id,
            'revoke_note' => $note,
            'revoked_via' => $via,
            'unhide_pending' => true,
        ];
    }

    /**
     * One conditional single-document write on a sanction that also appends its audit events with
     * consecutive versions (optimistic on `version`). Returns the document BEFORE the write, or null
     * when the condition no longer holds.
     *
     * @param  list<array{0: string, 1: ?string, 2: ?string, 3: array}>  $events  [type, actor, note, refs]
     */
    private function sanctionWrite(string $id, array $condition, array $set, array $events, bool $retry = true): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $doc = $this->collection()->findOne(['_id' => $id] + $condition);
            if ($doc === null) {
                return null;
            }
            $version = (int) ($doc['version'] ?? 0);
            $partition = (string) ($doc['audit_case_id'] ?? $id);
            $caseVersion = empty($doc['case_id']) ? null : ($this->cases->find((string) $doc['case_id'])['version'] ?? null);
            $built = [];
            foreach ($events as $i => [$type, $actor, $note, $refs]) {
                $built[] = ModerationEvents::make($type, $partition, 'sanction', $id, $version + $i + 1, $actor, $note,
                    $refs + ['sanction_id' => $id, 'report_id' => $doc['report_id'] ?? null, 'case_version' => $caseVersion]);
            }
            $update = ['$set' => $set + ['version' => $version + count($built), 'updated_at' => new UTCDateTime(now())]];
            if ($built) {
                $update['$push'] = ['events' => ['$each' => $built]];
            }
            $before = $this->collection()->findOneAndUpdate(
                ['_id' => $id, 'version' => $doc['version'] ?? null] + $condition, $update,
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_BEFORE],
            );
            if ($before !== null) {
                $this->snapshot->forget((string) $before['user_id']);
                $this->audit->publish($built);

                return $before;
            }
            if (! $retry) {
                return null;
            }
        }

        throw new ModerationException(ModerationCases::BUSY);
    }

    /** Reports whose REPORTED event is missing on their case (crash between insert and registration). */
    private function registerMissingReports(): int
    {
        $count = 0;
        $counts = $this->collection('reports')->aggregate([['$group' => ['_id' => '$case_id', 'n' => ['$sum' => 1]]]]);
        foreach ($counts as $row) {
            $case = $row['_id'] ? $this->cases->find((string) $row['_id']) : null;
            if ($case !== null && (int) $case['reports_count'] === (int) $row['n']) {
                continue;
            }
            $query = $row['_id'] ? Report::where('case_id', $row['_id']) : Report::whereNull('case_id');
            foreach ($query->get() as $report) {
                if (! $report->case_id) {
                    $report->case_id = $this->cases->ensureFor($report);
                    Report::where('_id', $report->id)->update(['case_id' => $report->case_id]);
                }
                if ($this->cases->register($report) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function collection(string $name = 'sanctions'): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
