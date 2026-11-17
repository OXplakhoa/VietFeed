<?php

namespace App\Console\Commands;

use App\Moderation\ModerationQueue;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

/** Gate 9b: rebuild the Redis review queue and counters from MongoDB case documents. */
final class ModerationRebuildQueue extends Command
{
    protected $signature = 'moderation:rebuild-queue';

    protected $description = 'Rebuild the moderation review queue (Redis) from MongoDB case state';

    public function handle(ModerationQueue $queue, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            $count = $queue->rebuild();
            if ($count < 0) {
                $this->warn('Another rebuild is running; try again shortly.');

                return self::FAILURE;
            }
            $this->info("Queue rebuilt: $count pending cases; pending appeals: ".$queue->pendingAppeals());
        } catch (\Throwable $e) {
            $this->error('Rebuild failed ('.$e::class.')');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
