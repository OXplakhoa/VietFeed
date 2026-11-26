<?php

namespace App\Console\Commands;

use App\Demo\SyntheticWorkload;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Throwable;

final class DemoSynthetic extends Command
{
    protected $signature = 'demo:synthetic {action : seed|inspect}
        {--seed= : Override the manifest seed (0..2147483647)}
        {--dry-run : Preview without creating readers or events}';

    protected $description = 'Seed or inspect a labelled synthetic demo workload';

    public function handle(SyntheticWorkload $workload, StoryPipeline $pipeline): int
    {
        if ($this->laravel->environment('production') || config('app.env') === 'production' || Env::get('APP_ENV') === 'production') {
            $this->error('The synthetic demo workload is disabled in production.');

            return self::FAILURE;
        }

        $action = $this->argument('action');
        if (! in_array($action, ['seed', 'inspect'], true)) {
            $this->error('Use seed or inspect.');

            return self::INVALID;
        }

        try {
            $pipeline->assertSafeTestStores();
            $manifest = $workload->manifest();
            $seed = filter_var($this->option('seed') ?? $manifest['seed'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 2147483647],
            ]);
            if ($seed === false) {
                $this->error('--seed must be an integer from 0 to 2147483647.');

                return self::INVALID;
            }
            $dryRun = (bool) $this->option('dry-run');
            if ($action === 'seed' && ! $dryRun) {
                $workload->seed($seed, $manifest);
            }
            $this->line(json_encode($workload->inspect($seed, $manifest) + ['dry_run' => $dryRun], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
