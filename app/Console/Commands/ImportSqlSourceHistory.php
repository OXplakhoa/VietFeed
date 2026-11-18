<?php

namespace App\Console\Commands;

use App\SourceHistory\SourceHistorySqlImport;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

class ImportSqlSourceHistory extends Command
{
    protected $signature = 'sources:import-history-sql {--force : Resume or deliberately repeat the legacy import}';

    protected $description = 'Copy retained legacy SQL fetch history to Cassandra during cutover';

    public function handle(SourceHistorySqlImport $import): int
    {
        try {
            foreach ($import->run((bool) $this->option('force')) as $name => $count) {
                $this->line("$name: $count");
            }

            return self::SUCCESS;
        } catch (LogicException $e) {
            $this->error($e->getMessage());
        } catch (Throwable $e) {
            $this->error('Source fetch history import failed ('.$e::class.'). Verify the stores and use --force to resume.');
        }

        return self::FAILURE;
    }
}
