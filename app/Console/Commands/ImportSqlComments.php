<?php

namespace App\Console\Commands;

use App\Content\CommentSqlImport;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

/** Gate 9.5 slice 2: idempotent data move of legacy SQL comments into MongoDB with moderation remap. */
final class ImportSqlComments extends Command
{
    protected $signature = 'comments:import-sql';

    protected $description = 'Copy SQL comments into MongoDB (parents first) and remap moderation comment references; safe to rerun';

    public function handle(CommentSqlImport $import, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            foreach ($import->run() as $name => $count) {
                $this->line(str_pad($name, 26).$count);
            }
        } catch (\Throwable $e) {
            $this->error('Import failed ('.$e::class.'): '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Next: php artisan moderation:audit reemit, then moderation:rebuild-queue (see docs/database/nosql-local.md).');

        return self::SUCCESS;
    }
}
