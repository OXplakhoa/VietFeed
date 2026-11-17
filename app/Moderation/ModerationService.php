<?php

namespace App\Moderation;

use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moderation commands (report, decide, sanction, appeal, revoke).
 *
 * Gate 9a keeps case state on the current SQL storage; controllers only talk to this
 * class and to {@see ModerationQueries}, so Gate 9b can swap storage behind them.
 * Moderation evidence is never hard-deleted: reports/sanctions are only ever closed.
 */
class ModerationService
{
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
        if (Report::where('reporter_id', (string) $reporter->id)->where('comment_id', $comment->id)->exists()) {
            throw $duplicate;
        }

        try {
            return Report::create([
                'reporter_id' => (string) $reporter->id,
                'comment_id' => $comment->id,
                'reason' => $reason,
                'description' => $description,
                'comment_body' => $comment->body,
                'comment_user_id' => (string) $comment->user_id,
                'comment_article_id' => (string) $comment->article_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $duplicate;
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

        try {
            return DB::transaction(function () use ($admin, $report, $status, $note) {
                $report = $this->lockPendingCase($report);

                return $this->decidePending($report, $admin, $status, $note, null);
            }, 3);
        } catch (QueryException $e) {
            // Lock timeout / deadlock after retries: a clear message instead of a 500.
            throw new ModerationException('Hệ thống đang bận xử lý báo cáo này. Vui lòng thử lại.');
        }
    }

    /**
     * Issue a sanction, optionally from a case and optionally hiding the reported comment.
     *
     * @throws ModerationException
     */
    public function issueSanction(User $admin, User $target, string $type, string $reason, ?int $durationDays, ?int $reportId = null, bool $hideComment = false, ?string $note = null): Sanction
    {
        if ($reportId === null && $hideComment) {
            throw new ModerationException('Chỉ có thể ẩn bình luận khi xử lý từ một báo cáo.');
        }

        $expiresAt = in_array($type, ['mute', 'temporary_ban'], true) ? now()->addDays($durationDays ?? 7) : null;

        return DB::transaction(function () use ($admin, $target, $type, $reason, $expiresAt, $reportId, $hideComment, $note) {
            $report = null;
            if ($reportId !== null) {
                $report = $this->lockPendingCase(Report::findOrFail($reportId));
                // Reports filed before the snapshot existed fall back to the live comment.
                $authorId = $report->comment_user_id ?? Comment::find($report->comment_id)?->user_id;
                if ($authorId !== null && (string) $authorId !== (string) $target->id) {
                    throw new ModerationException('Người bị xử lý phải là tác giả của bình luận bị báo cáo.');
                }
            }

            $sanction = Sanction::create([
                'user_id' => (string) $target->id,
                'admin_id' => (string) $admin->id,
                'report_id' => $report?->id,
                'type' => $type,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'is_active' => true,
            ]);

            if ($report) {
                $this->decidePending($report, $admin, 'actioned', $note, $sanction->id);
                if ($hideComment) {
                    $this->hideComment($report->comment_id, $sanction);
                }
            }

            return $sanction;
        });
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

        // Conditional update: a concurrent second submit changes 0 rows and never overwrites the first message.
        $changed = Sanction::where('id', $sanction->id)->where('appeal_status', 'none')
            ->where('is_active', true)->whereNull('revoked_at')
            ->update(['appeal_message' => $message, 'appeal_status' => 'pending', 'appealed_at' => now(), 'updated_at' => now()]);
        if ($changed === 0) {
            throw new ModerationException('Bạn chỉ có thể kháng cáo một lần.', 403);
        }
    }

    /** @throws ModerationException */
    public function decideAppeal(User $admin, Sanction $sanction, string $decision, ?string $note): Sanction
    {
        return DB::transaction(function () use ($admin, $sanction, $decision, $note) {
            $sanction = Sanction::lockForUpdate()->findOrFail($sanction->id);
            if ($sanction->appeal_status !== 'pending') {
                throw new ModerationException('Chỉ có thể quyết định các kháng cáo đang chờ duyệt.');
            }

            $attributes = [
                'appeal_status' => $decision,
                'appeal_decided_at' => now(),
                'appeal_decided_by' => (string) $admin->id,
                'appeal_decision_note' => $note,
            ];

            $revoke = $decision === 'approved' && $sanction->revoked_at === null;
            if ($revoke) {
                $attributes += $this->revocation($admin, $note, 'appeal');
            }
            $sanction->update($attributes);

            if ($revoke) {
                $this->unhideCommentsOf($sanction);
            }

            return $sanction;
        });
    }

    /**
     * Revoke (replaces delete): the sanction stays in history with who/when/why.
     * Undoing the sanction also unhides every comment this very sanction hid.
     *
     * @throws ModerationException
     */
    public function revokeSanction(User $admin, Sanction $sanction, ?string $note): Sanction
    {
        return DB::transaction(function () use ($admin, $sanction, $note) {
            $sanction = Sanction::lockForUpdate()->findOrFail($sanction->id);
            if ($sanction->revoked_at !== null || ! $sanction->is_active) {
                throw new ModerationException('Biện pháp xử lý này đã được thu hồi hoặc không còn hiệu lực.');
            }
            $sanction->update($this->revocation($admin, $note, 'admin'));
            $this->unhideCommentsOf($sanction);

            return $sanction;
        });
    }

    /**
     * Lock every pending report of the comment in id order (same order in every path) and return the
     * requested report; refuse when it is no longer pending, i.e. another action already resolved the case.
     *
     * @throws ModerationException
     */
    private function lockPendingCase(Report $report): Report
    {
        $pending = Report::where('comment_id', $report->comment_id)->where('status', 'pending')
            ->orderBy('id')->lockForUpdate()->get();
        $target = $pending->firstWhere('id', $report->id);
        if ($target === null) {
            throw new ModerationException('Báo cáo này đã được xử lý và không thể thay đổi.');
        }

        return $target;
    }

    private function revocation(User $admin, ?string $note, string $via): array
    {
        return [
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => (string) $admin->id,
            'revoke_note' => $note,
            'revoked_via' => $via,
        ];
    }

    private function decidePending(Report $report, User $admin, string $status, ?string $note, ?int $sanctionId): int
    {
        return Report::where('comment_id', $report->comment_id)->where('status', 'pending')->update([
            'status' => $status,
            'admin_id' => (string) $admin->id,
            'decided_at' => now(),
            'decision_note' => $note,
            'sanction_id' => $sanctionId,
            'updated_at' => now(),
        ]);
    }

    /** The only code path that sets is_hidden = true. */
    private function hideComment(int $commentId, Sanction $sanction): void
    {
        // Only a currently visible comment is taken over; one already hidden by another sanction keeps its owner.
        Comment::where('id', $commentId)->where('is_hidden', false)->update(['is_hidden' => true, 'hidden_by_sanction_id' => $sanction->id]);
    }

    /** The only code path that clears is_hidden: comments hidden by this very sanction. */
    private function unhideCommentsOf(Sanction $sanction): void
    {
        Comment::where('hidden_by_sanction_id', $sanction->id)->update(['is_hidden' => false, 'hidden_by_sanction_id' => null]);
    }
}
