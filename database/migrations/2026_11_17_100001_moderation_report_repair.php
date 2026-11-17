<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate 9a: moderation evidence is never hard-deleted.
 * - reports keep a snapshot of the comment and survive comment deletion (FK cascade removed);
 * - `reviewed` status is removed (legacy rows become `pending`);
 * - reports/sanctions/comments get the columns for decisions, revocation and hide tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rerun-safe: down() deliberately does not restore the cascading FK, the enum or legacy data.
        $hasFk = collect(Schema::getForeignKeys('reports'))->contains(fn ($fk) => $fk['columns'] === ['comment_id']);
        if ($hasFk) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropForeign(['comment_id']);
            });
        }

        // A legacy `reviewed` case had no decision, so it returns to `pending` without admin/decided_at.
        $reviewed = DB::table('reports')->where('status', 'reviewed');
        $reviewed->update(Schema::hasColumn('reports', 'reviewed_at')
            ? ['status' => 'pending', 'reviewed_at' => null, 'admin_id' => null]
            : ['status' => 'pending', 'decided_at' => null, 'admin_id' => null]);

        Schema::table('reports', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        if (Schema::hasColumn('reports', 'reviewed_at') && ! Schema::hasColumn('reports', 'decided_at')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->renameColumn('reviewed_at', 'decided_at');
            });
        }

        Schema::table('reports', function (Blueprint $table) {
            if (! Schema::hasColumn('reports', 'comment_body')) {
                $table->text('comment_body')->nullable()->after('description');
            }
            if (! Schema::hasColumn('reports', 'comment_user_id')) {
                $table->string('comment_user_id')->nullable()->index()->after('comment_body');
            }
            if (! Schema::hasColumn('reports', 'comment_article_id')) {
                $table->string('comment_article_id')->nullable()->after('comment_user_id');
            }
            if (! Schema::hasColumn('reports', 'decision_note')) {
                $table->text('decision_note')->nullable()->after('decided_at');
            }
            if (! Schema::hasColumn('reports', 'sanction_id')) {
                $table->unsignedBigInteger('sanction_id')->nullable()->index()->after('decision_note');
            }
        });

        // Backfill snapshots only for rows that have none yet (comment may already be gone).
        DB::statement('UPDATE reports SET
            comment_body = (SELECT body FROM comments WHERE comments.id = reports.comment_id),
            comment_user_id = (SELECT user_id FROM comments WHERE comments.id = reports.comment_id),
            comment_article_id = (SELECT article_id FROM comments WHERE comments.id = reports.comment_id)
            WHERE comment_body IS NULL');

        $sanctionColumns = [
            'revoked_at' => fn (Blueprint $t) => $t->timestamp('revoked_at')->nullable()->after('appeal_status'),
            'revoked_by' => fn (Blueprint $t) => $t->string('revoked_by')->nullable()->after('revoked_at'),
            'revoke_note' => fn (Blueprint $t) => $t->text('revoke_note')->nullable()->after('revoked_by'),
            'revoked_via' => fn (Blueprint $t) => $t->string('revoked_via', 20)->nullable()->after('revoke_note'),
            'appealed_at' => fn (Blueprint $t) => $t->timestamp('appealed_at')->nullable()->after('revoked_via'),
            'appeal_decided_at' => fn (Blueprint $t) => $t->timestamp('appeal_decided_at')->nullable()->after('appealed_at'),
            'appeal_decided_by' => fn (Blueprint $t) => $t->string('appeal_decided_by')->nullable()->after('appeal_decided_at'),
            'appeal_decision_note' => fn (Blueprint $t) => $t->text('appeal_decision_note')->nullable()->after('appeal_decided_by'),
        ];
        Schema::table('sanctions', function (Blueprint $table) use ($sanctionColumns) {
            foreach ($sanctionColumns as $name => $add) {
                if (! Schema::hasColumn('sanctions', $name)) {
                    $add($table);
                }
            }
        });

        // Legacy approved appeals deactivated the sanction without recording who/when.
        DB::table('sanctions')->where('appeal_status', 'approved')->where('is_active', false)->whereNull('revoked_at')
            ->update(['revoked_at' => DB::raw('updated_at'), 'revoked_via' => 'appeal']);

        if (! Schema::hasColumn('comments', 'hidden_by_sanction_id')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->unsignedBigInteger('hidden_by_sanction_id')->nullable()->index()->after('is_hidden');
            });
        }
    }

    public function down(): void
    {
        // Rolling back would drop moderation evidence; refuse instead of losing it.
        $evidence = DB::table('reports')->where(fn ($q) => $q->whereNotNull('comment_body')->orWhereNotNull('comment_user_id')
            ->orWhereNotNull('comment_article_id')->orWhereNotNull('decision_note')->orWhereNotNull('sanction_id'))->exists()
            || DB::table('sanctions')->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhereNotNull('revoked_by')
                ->orWhereNotNull('revoke_note')->orWhereNotNull('revoked_via')->orWhereNotNull('appealed_at')
                ->orWhereNotNull('appeal_decided_at')->orWhereNotNull('appeal_decided_by')->orWhereNotNull('appeal_decision_note'))->exists()
            || DB::table('comments')->whereNotNull('hidden_by_sanction_id')->exists();
        if ($evidence) {
            throw new RuntimeException('Refusing to roll back moderation_report_repair: report snapshots, revocations, appeal decisions or hide links exist and would be lost.');
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['hidden_by_sanction_id']);
            $table->dropColumn('hidden_by_sanction_id');
        });

        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropColumn(['revoked_at', 'revoked_by', 'revoke_note', 'revoked_via', 'appealed_at', 'appeal_decided_at', 'appeal_decided_by', 'appeal_decision_note']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['comment_user_id']);
            $table->dropIndex(['sanction_id']);
            $table->dropColumn(['comment_body', 'comment_user_id', 'comment_article_id', 'decision_note', 'sanction_id']);
            $table->renameColumn('decided_at', 'reviewed_at');
        });
    }
};
