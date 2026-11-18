<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        $collection = DB::connection('mongodb')->getCollection('article_unlocks');
        foreach (array_keys(self::INDEXES) as $name) {
            $collection->dropIndex($name);
        }
    }
};
