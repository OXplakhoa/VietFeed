<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

// Gate 9.5 slice 5: failed jobs (`database-uuids` provider) and job batches live on MongoDB.
// createIndex with the same spec is a no-op, so reruns are safe.
return new class extends Migration
{
    public function up(): void
    {
        $mongo = DB::connection('mongodb');
        // queue:retry / queue:forget look jobs up by uuid; queue:flush --hours filters on failed_at.
        $mongo->getCollection('failed_jobs')->createIndex(['uuid' => 1], ['unique' => true, 'name' => 'uuid_1']);
        $mongo->getCollection('failed_jobs')->createIndex(['failed_at' => 1], ['name' => 'failed_at_1']);
        // queue:prune-batches filters on created_at/finished_at.
        $mongo->getCollection('job_batches')->createIndex(['created_at' => 1], ['name' => 'created_at_1']);
    }

    public function down(): void
    {
        // Indexes only; failed job documents are never dropped by a rollback.
        $this->dropIndex('failed_jobs', 'uuid_1');
        $this->dropIndex('failed_jobs', 'failed_at_1');
        $this->dropIndex('job_batches', 'created_at_1');
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
