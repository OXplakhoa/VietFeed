<?php

namespace App\Console\Commands;

use App\Moderation\ModerationCases;
use App\Moderation\ModerationService;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

/** Gate 9b: finish interrupted moderation protocol steps (idempotent). */
final class ModerationRepair extends Command
{
    protected $signature = 'moderation:repair {--lease='.ModerationCases::CLAIM_LEASE_SECONDS.' : Seconds before an in-flight sanction claim is considered abandoned}';

    protected $description = 'Converge moderation case documents after an interrupted action (register, confirm/release, settle, hide/unhide)';

    public function handle(ModerationService $moderation, StoryPipeline $pipeline): int
    {
        $lease = filter_var($this->option('lease'), FILTER_VALIDATE_INT, ['options' => ['min_range' => ModerationCases::MIN_LEASE_SECONDS]]);
        if ($lease === false) {
            $this->error('--lease must be an integer >= '.ModerationCases::MIN_LEASE_SECONDS.' seconds');

            return self::FAILURE;
        }
        try {
            $pipeline->assertSafeTestStores();
            foreach ($moderation->repair($lease) as $step => $count) {
                $this->line("$step: $count");
            }
        } catch (\Throwable $e) {
            $this->error('Repair failed ('.$e::class.')');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
