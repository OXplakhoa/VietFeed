<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('story_briefs');
        $collection->createIndex(['story_id' => 1, '_id' => -1], ['name' => 'story_brief_history']);
        $collection->createIndex(['story_id' => 1, 'story_version' => 1, 'kind' => 1], [
            'name' => 'story_brief_extractive_version', 'unique' => true, 'partialFilterExpression' => ['kind' => 'extractive'],
        ]);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('story_briefs');
        foreach (['story_brief_history', 'story_brief_extractive_version'] as $name) {
            try {
                $collection->dropIndex($name);
            } catch (CommandException $e) {
                if (! in_array($e->getCode(), [26, 27], true)) {
                    throw $e;
                }
            }
        }
    }
};
