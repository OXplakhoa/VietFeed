<?php

namespace App\Console\Commands;

use App\Interaction\InteractionActivity;
use App\Interaction\InteractionReplaySource;
use App\Interaction\InteractionStream;
use App\Interaction\MongoInteractionReplaySource;
use App\Interaction\Trending;
use Illuminate\Console\Command;

final class Interactions extends Command
{
    protected $signature = 'interactions {action : schema|consume|inspect|replay|top|rebuild|reemit}
        {--consumer=local : Unique worker name}
        {--limit=10 : Maximum deliveries or ranked articles (1..100)}
        {--id= : Dead-letter entry ID to replay}
        {--synthetic : Use the separate synthetic demo ranking for top or rebuild}';

    protected $description = 'Project interaction activity and manage Redis trending';

    public function handle(InteractionStream $stream, Trending $trending, InteractionReplaySource $source, MongoInteractionReplaySource $mongo, InteractionActivity $activity): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            switch ($this->argument('action')) {
                case 'schema':
                    $activity->bootstrap();
                    $this->info('Interaction activity tables ready');
                    break;
                case 'consume':
                    $this->info('Processed '.$stream->consume($this->option('consumer'), $limit).' deliveries');
                    break;
                case 'inspect':
                    $this->line(json_encode(['lengths' => $stream->lengths(), 'pending_entries' => $stream->pending(), 'dead_letter_entries' => $stream->deadLetters()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'replay':
                    $this->info('Requeued '.$stream->replay((string) $this->option('id')));
                    break;
                case 'top':
                    $this->line(json_encode($trending->top($limit, synthetic: (bool) $this->option('synthetic')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'rebuild':
                    if ($this->option('synthetic')) {
                        $this->info('Rebuilt '.$trending->rebuild($source, synthetic: true).' synthetic events');
                    } else {
                        $this->info('Rebuilt '.$trending->rebuild($mongo).' active MongoDB boosts');
                    }
                    break;
                case 'reemit':
                    $counts = $mongo->reemit($stream);
                    $this->info("Published {$counts['published']} current memberships, {$counts['failed']} failed");

                    return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
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
