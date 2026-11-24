<?php

namespace App\Console\Commands;

use App\Console\ProjectionConsumeBudget;
use App\Moderation\ModerationAudit;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

/** Gate 9b: moderation audit projection (Redis Stream -> Cassandra) operations. */
final class ModerationAuditCommand extends Command
{
    protected $signature = 'moderation:audit {action : schema|consume|inspect|replay|reemit|timeline}
        {--consumer=local : Unique consumer name}
        {--limit=10 : Maximum deliveries (1..100)}
        {--id= : DLQ entry ID (replay)}
        {--case= : Case id (audit partition) for reemit/timeline; omit to reemit everything}';

    protected $description = 'Moderation audit: Cassandra schema, consume, inspect, replay DLQ, reemit canonical events, read a timeline';

    public function handle(ModerationAudit $audit, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            $case = $this->option('case');
            switch ($this->argument('action')) {
                case 'schema':
                    $audit->bootstrap();
                    $this->info('Cassandra moderation audit schema ready');
                    break;
                case 'consume':
                    $consumer = (string) $this->option('consumer');
                    $count = ProjectionConsumeBudget::run($consumer, $limit, fn () => $audit->consume($consumer, 1));
                    $this->info('Processed '.$count.' deliveries');
                    break;
                case 'inspect':
                    $this->line(json_encode($audit->overview(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'replay':
                    $this->info('Requeued '.$audit->stream()->replay((string) $this->option('id')));
                    break;
                case 'reemit':
                    $this->info('Re-emitted '.$audit->reemit($case ?: null).' canonical events');
                    break;
                case 'timeline':
                    if (! $case) {
                        throw new \InvalidArgumentException('--case is required');
                    }
                    $this->line(json_encode($audit->timeline($case), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown moderation audit action');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
