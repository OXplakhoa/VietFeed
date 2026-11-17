<?php

namespace App\Console\Commands;

use App\Moderation\SqlImport;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

/** Gate 9b: idempotent data move of legacy SQL reports/sanctions into MongoDB. */
final class ModerationImportSql extends Command
{
    protected $signature = 'moderation:import-sql';

    protected $description = 'Copy SQL reports/sanctions into MongoDB (cases, backfill audit events, queue rebuild); safe to rerun';

    public function handle(SqlImport $import, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            foreach ($import->run() as $name => $count) {
                $this->line(str_pad($name, 20).$count);
            }
        } catch (\Throwable $e) {
            $this->error('Import failed ('.$e::class.'): '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
