<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        $mongo = DB::connection('mongodb');
        $mongo->getCollection('categories')->dropIndex('slug_1');
        $mongo->getCollection('sources')->dropIndex('feed_url_1');
        $mongo->getCollection('sources')->dropIndex('category_id_1');
    }
};
