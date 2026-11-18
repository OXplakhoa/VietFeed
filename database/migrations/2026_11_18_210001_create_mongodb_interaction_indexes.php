<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookmarks', 'boosts'] as $name) {
            $collection = DB::connection('mongodb')->getCollection($name);
            $collection->createIndex(['user_id' => 1, 'article_id' => 1], ['unique' => true]);
            $collection->createIndex(['user_id' => 1, 'created_at' => -1]);
            $collection->createIndex(['article_id' => 1]);
        }
    }

    public function down(): void
    {
        foreach (['bookmarks', 'boosts'] as $name) {
            foreach (['user_id_1_article_id_1', 'user_id_1_created_at_-1', 'article_id_1'] as $index) {
                $this->dropIndex($name, $index);
            }
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
