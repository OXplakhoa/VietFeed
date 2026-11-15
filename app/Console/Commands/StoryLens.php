<?php

namespace App\Console\Commands;

use App\Content\ContentCatalog;
use App\StoryLens\StoryLens as StoryLensRead;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Illuminate\Console\Command;

final class StoryLens extends Command
{
    protected $signature = 'storylens {action : ingest|schema|consume|inspect|replay|reemit|read}
        {--fixture= : Frozen RSS JSON path (defaults to versioned fixture)}
        {--section=graph : timeline or graph}
        {--consumer=local : Unique consumer name}
        {--limit=10 : Maximum deliveries (1..100)}
        {--id= : DLQ entry ID}
        {--story= : Canonical Story UUID; omit for all in inspect/reemit}';

    protected $description = 'StoryLens canonical ingest, bounded projections and recovery';

    public function handle(ContentCatalog $catalog, StoryPipeline $pipeline, StoryTimeline $timeline, StoryLensRead $lens): int
    {
        try {
            $pipeline->assertSafeTestStores();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            $section = $this->option('section');
            switch ($this->argument('action')) {
                case 'ingest':
                    $fixture = $this->option('fixture') ?: base_path('tests/Fixtures/storylens-rss-v1.json');
                    $this->info('Ingested '.$catalog->ingest(json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR)).' Articles');
                    break;
                case 'schema':
                    $timeline->bootstrap();
                    $this->info('Cassandra Story timeline schema ready');
                    break;
                case 'consume':
                    $this->info('Processed '.$pipeline->consume($section, $this->option('consumer'), $limit).' deliveries');
                    break;
                case 'inspect':
                    $this->line(json_encode($pipeline->inspect($section, $this->option('story')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'replay':
                    $this->info('Requeued '.$pipeline->stream($section)->replay((string) $this->option('id')));
                    break;
                case 'reemit':
                    $this->info('Re-emitted '.$pipeline->reemit($this->option('story')).' canonical events; both sections pending');
                    break;
                case 'read':
                    $this->line(json_encode($lens->read((string) $this->option('story')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown StoryLens action');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
