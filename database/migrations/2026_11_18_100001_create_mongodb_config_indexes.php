<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        $mongo = DB::connection('mongodb');
        $mongo->getCollection('categories')->createIndex(['slug' => 1], ['unique' => true]);
        $mongo->getCollection('sources')->createIndex(['feed_url' => 1], ['unique' => true]);
        $mongo->getCollection('sources')->createIndex(['category_id' => 1]);
    }

    public function down(): void
    {
        $this->dropIndex('categories', 'slug_1');
        $this->dropIndex('sources', 'feed_url_1');
        $this->dropIndex('sources', 'category_id_1');
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
