<?php

namespace App\Console\Commands;

use App\Interaction\InteractionReplaySource;
use App\Interaction\InteractionStream;
use App\Interaction\Trending;
use Illuminate\Console\Command;

final class Interactions extends Command
{
    protected $signature = 'interactions {action : consume|inspect|replay|top|rebuild}
        {--consumer=local : Unique worker name}
        {--limit=10 : Maximum deliveries or ranked articles (1..100)}
        {--id= : Dead-letter entry ID to replay}
        {--synthetic : Explicitly accept the synthetic v1 fixture for rebuild}';

    protected $description = 'Gate 5 interaction stream and synthetic trending proof';

    public function handle(InteractionStream $stream, Trending $trending, InteractionReplaySource $source): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            switch ($this->argument('action')) {
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
                    $this->line(json_encode($trending->top($limit), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'rebuild':
                    if (! $this->option('synthetic')) {
                        throw new \InvalidArgumentException('Only synthetic replay is wired; pause consumers and pass --synthetic');
                    }
                    $this->info('Rebuilt '.$trending->rebuild($source).' synthetic events');
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
