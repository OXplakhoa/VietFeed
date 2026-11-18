<?php

namespace App\Console\Commands;

use App\Interaction\InteractionSqlImport;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;
use Throwable;

final class ImportSqlInteractions extends Command
{
    protected $signature = 'interactions:import-sql
        {--emit : Publish backfill events for newly copied rows}
        {--force : Allow a repeat import during the cutover window}';

    protected $description = 'Copy legacy SQL bookmarks and boosts into MongoDB by user and article';

    public function handle(InteractionSqlImport $import, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            if (! $this->option('force') && $import->hasImportedMemberships()) {
                $this->error('Legacy interactions already exist. Run this import once, in the cutover window. Use --force only to resume an intentional cutover import.');

                return self::FAILURE;
            }
            $results = $import->run((bool) $this->option('emit'));
            foreach ($results as $table => $counts) {
                $this->line($table.' '.http_build_query($counts, arg_separator: ' '));
            }
        } catch (Throwable $e) {
            $this->error('Interaction import failed ('.$e::class.')');

            return self::FAILURE;
        }

        if (array_sum(array_column($results, 'publish_failed')) > 0) {
            $this->error('Backfill publication incomplete. Run interactions reemit after Redis recovers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
