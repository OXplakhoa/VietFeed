<?php

namespace App\Console\Commands;

use App\Content\ConfigSqlImport;
use Illuminate\Console\Command;

class ImportSqlConfig extends Command
{
    protected $signature = 'config:import-sql';

    protected $description = 'Copy legacy SQL categories/sources and remap references (maintenance window, rerunnable)';

    public function handle(ConfigSqlImport $import): int
    {
        try {
            $counts = $import->run();
        } catch (\LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        foreach ($counts as $name => $count) {
            $this->line($name.': '.$count);
        }

        return self::SUCCESS;
    }
}
