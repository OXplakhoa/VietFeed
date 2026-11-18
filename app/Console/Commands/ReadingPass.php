<?php

namespace App\Console\Commands;

use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\ReadingPass\UnlockLedger;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Console\Command;

final class ReadingPass extends Command
{
    protected $signature = 'reading-pass {action : schema|consume|inspect|replay|reemit|history|rebuild}
        {--consumer=local : Unique worker name}
        {--limit=10 : Maximum deliveries (1..100)}
        {--id= : Dead-letter entry ID to replay}
        {--user= : User id for reemit or history}
        {--month= : UTC month YYYY-MM for history}';

    protected $description = 'Reading Pass unlock history (Cassandra) and Redis quota windows';

    public function handle(ReadingPassHistory $history, ReadingPassQuota $quota, UnlockLedger $ledger, StoryPipeline $pipeline): int
    {
        try {
            $pipeline->assertSafeTestStores();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            $stream = $history->stream();
            switch ($this->argument('action')) {
                case 'schema':
                    $history->bootstrap();
                    $this->info('Reading Pass history tables ready');
                    break;
                case 'consume':
                    $this->info('Processed '.$history->consume($this->option('consumer'), $limit).' deliveries');
                    break;
                case 'inspect':
                    $this->line(json_encode(['lengths' => $stream->lengths(), 'pending_entries' => $stream->pending(), 'dead_letter_entries' => $stream->deadLetters()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'replay':
                    $this->info('Requeued '.$stream->replay((string) $this->option('id')));
                    break;
                case 'reemit':
                    $counts = $history->reemit($ledger, $this->option('user') ?: null);
                    $this->info("Published {$counts['published']} unlocks, {$counts['failed']} failed");

                    return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
                case 'history':
                    $user = (string) $this->option('user');
                    if ($user === '') {
                        throw new \InvalidArgumentException('--user is required');
                    }
                    $this->line(json_encode($history->forUserMonth($user, (string) ($this->option('month') ?: now('UTC')->format('Y-m'))), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    break;
                case 'rebuild':
                    $quota->invalidateAll();
                    $this->info('Reading Pass windows invalidated; each rebuilds from MongoDB on its next decision');
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown action');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
