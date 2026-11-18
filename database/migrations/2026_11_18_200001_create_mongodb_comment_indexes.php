<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Gate 9.5 slice 2: comments on MongoDB. createIndex is idempotent, so reruns are safe.
return new class extends Migration
{
    public function up(): void
    {
        $comments = DB::connection('mongodb')->getCollection('comments');
        // Article page (roots newest first) and admin lists.
        $comments->createIndex(['article_id' => 1, 'created_at' => -1]);
        // Replies eager load and the delete cascade.
        $comments->createIndex(['parent_id' => 1]);
        // Per-user counts (admin user list).
        $comments->createIndex(['user_id' => 1]);
        // Moderation unhide (`hidden_by_sanction_id = S`) and the revoked-hide repair sweep. Visible comments
        // store an explicit null, so a sparse index would not skip them: index only real sanction ids.
        // `$gt: ''` (type bracketing: non-empty strings only) rather than `$type: 'string'`, because the
        // planner proves `{hidden_by_sanction_id: S}` implies the range but not the $type predicate.
        // An earlier revision of this unmerged migration created a sparse index under the default name;
        // replace it (createIndex refuses a same-name index with different options).
        foreach ($comments->listIndexes() as $index) {
            $current = isset($index['partialFilterExpression']) ? json_encode($index['partialFilterExpression']) : null;
            if ($index->getName() === 'hidden_by_sanction_id_1' && $current !== json_encode(['hidden_by_sanction_id' => ['$gt' => '']])) {
                $comments->dropIndex('hidden_by_sanction_id_1');
            }
        }
        $comments->createIndex(['hidden_by_sanction_id' => 1], ['partialFilterExpression' => ['hidden_by_sanction_id' => ['$gt' => '']]]);
        $comments->createIndex(['created_at' => -1]);
        // Data move `comments:import-sql`: one Mongo copy per legacy SQL row.
        $comments->createIndex(['legacy_sql_id' => 1], ['unique' => true, 'partialFilterExpression' => ['legacy_sql_id' => ['$exists' => true]]]);
    }

    public function down(): void
    {
        // Indexes only; comment documents are never dropped by a rollback.
        DB::connection('mongodb')->getCollection('comments')->dropIndexes();
    }
};
