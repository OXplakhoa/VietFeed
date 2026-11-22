<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

// Gate 9.5 slice 3b: Reading Pass ledger indexes (idempotent: createIndex with the same spec is a no-op).
return new class extends Migration
{
    private const INDEXES = [
        'subject_1_article_id_1' => [['subject' => 1, 'article_id' => 1], ['unique' => true]],
        'subject_1_unlocked_at_1' => [['subject' => 1, 'unlocked_at' => 1], []],
        'subject_1_fallback_1' => [['subject' => 1, 'fallback' => 1], ['partialFilterExpression' => ['fallback' => true]]],
        'state_1_unlocked_at_1' => [['state' => 1, 'unlocked_at' => 1], []],
        'user_id_1' => [['user_id' => 1], []],
        'article_id_1' => [['article_id' => 1], []],
    ];

    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('article_unlocks');
        foreach (self::INDEXES as $name => [$keys, $options]) {
            $collection->createIndex($keys, $options + ['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            $this->dropIndex('article_unlocks', $name);
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
