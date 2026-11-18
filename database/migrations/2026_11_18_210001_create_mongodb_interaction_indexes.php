<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
            $collection = DB::connection('mongodb')->getCollection($name);
            foreach (['user_id_1_article_id_1', 'user_id_1_created_at_-1', 'article_id_1'] as $index) {
                $collection->dropIndex($index);
            }
        }
    }
};
