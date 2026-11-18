<?php

namespace App\Console\Commands;

use App\ReadingPass\ReadingPassSqlImport;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;
use Throwable;

final class ImportSqlUnlocks extends Command
{
    protected $signature = 'unlocks:import-sql
        {--emit : Publish backfill history events for newly copied unlocks}
        {--force : Allow a repeat import during the cutover window}';

    protected $description = 'Copy legacy SQL Reading Pass unlocks into the MongoDB ledger by subject and article';

    public function handle(ReadingPassSqlImport $import, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            if (! $this->option('force') && $import->hasImported()) {
                $this->error('Legacy unlocks already exist. Run this import once, in the cutover window. Use --force only to resume an intentional cutover import.');

                return self::FAILURE;
            }
            $counts = $import->run((bool) $this->option('emit'));
            $this->line('article_unlocks '.http_build_query($counts, arg_separator: ' '));
        } catch (Throwable $e) {
            $this->error('Unlock import failed ('.$e::class.')');

            return self::FAILURE;
        }

        if ($counts['publish_failed'] > 0) {
            $this->error('Backfill publication incomplete. Run reading-pass reemit after Redis recovers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
