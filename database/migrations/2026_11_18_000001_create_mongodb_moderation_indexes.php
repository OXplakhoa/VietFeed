<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

// Gate 9b: moderation state on MongoDB. createIndex is idempotent, so reruns are safe.
// The unique indexes carry the invariants that 9a held with SQL constraints and locks.
return new class extends Migration
{
    public function up(): void
    {
        $mongo = DB::connection('mongodb');

        $reports = $mongo->getCollection('reports');
        $reports->createIndex(['reporter_id' => 1, 'comment_id' => 1], ['unique' => true, 'name' => 'reporter_comment_unique']);
        $reports->createIndex(['comment_id' => 1, 'status' => 1]);
        $reports->createIndex(['case_id' => 1]);
        $reports->createIndex(['status' => 1, 'created_at' => -1]);
        $reports->createIndex(['sanction_id' => 1]);
        $reports->createIndex(['legacy_sql_id' => 1], ['unique' => true, 'partialFilterExpression' => ['legacy_sql_id' => ['$exists' => true]]]);

        $sanctions = $mongo->getCollection('sanctions');
        $sanctions->createIndex(['user_id' => 1, 'is_active' => 1, 'created_at' => -1]);
        $sanctions->createIndex(['appeal_status' => 1]);
        $sanctions->createIndex(['created_at' => -1]);
        $sanctions->createIndex(['revoked_at' => 1]);
        $sanctions->createIndex(['audit_case_id' => 1]);
        $sanctions->createIndex(['unhide_pending' => 1], ['sparse' => true]);
        $sanctions->createIndex(['legacy_sql_id' => 1], ['unique' => true, 'partialFilterExpression' => ['legacy_sql_id' => ['$exists' => true]]]);

        $cases = $mongo->getCollection('moderation_cases');
        $cases->createIndex(['comment_id' => 1], ['unique' => true]);
        // Same order as the Redis priority queue, used when the projection is missing.
        $cases->createIndex(['status' => 1, 'pending_count' => -1, 'severity' => -1, 'round_started_at' => 1]);
        $cases->createIndex(['decisions.state' => 1]);
        $cases->createIndex(['updated_at' => 1]);
    }

    public function down(): void
    {
        foreach (['reports', 'sanctions', 'moderation_cases'] as $name) {
            // Indexes only; documents (moderation evidence) are never dropped by a rollback.
            $this->dropIndex($name, null);
        }
    }

    /** REVIEW-1 m1: a rollback tolerates an index (or collection) that is already gone. */
    private function dropIndex(string $collection, ?string $index): void
    {
        try {
            $index === null
                ? DB::connection('mongodb')->getCollection($collection)->dropIndexes()
                : DB::connection('mongodb')->getCollection($collection)->dropIndex($index);
        } catch (CommandException $e) {
            if (! in_array($e->getCode(), [26, 27], true)) { // NamespaceNotFound, IndexNotFound
                throw $e;
            }
        }
    }
};
