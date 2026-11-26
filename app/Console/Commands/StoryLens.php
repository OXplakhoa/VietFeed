<?php

namespace App\Console\Commands;

use App\Console\ProjectionConsumeBudget;
use App\Content\ContentCatalog;
use App\Models\Story;
use App\StoryLens\Brief\Briefs;
use App\StoryLens\StoryLens as StoryLensRead;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class StoryLens extends Command
{
    protected $signature = 'storylens {action : ingest|schema|consume|inspect|replay|reemit|read|brief|brief-eval}
        {--fixture= : Frozen RSS JSON path (defaults to versioned fixture)}
        {--section=graph : timeline or graph}
        {--consumer=local : Unique consumer name}
        {--limit= : Maximum deliveries or evaluation Stories (1..100)}
        {--id= : DLQ entry ID}
        {--story= : Canonical Story UUID}
        {--all : Select recent Stories for brief generation (maximum 50)}
        {--sync : Generate in this process for a demo}
        {--json : Print brief evaluation as JSON}';

    protected $description = 'StoryLens canonical ingest, bounded projections and recovery';

    public function handle(ContentCatalog $catalog, StoryPipeline $pipeline, StoryTimeline $timeline, StoryLensRead $lens, Briefs $briefs): int
    {
        try {
            $pipeline->assertSafeTestStores();
            $action = $this->argument('action');
            $defaultLimit = $action === 'brief-eval' ? Briefs::RECENT_LIMIT : 10;
            $limit = filter_var($this->option('limit') ?? $defaultLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                throw new \InvalidArgumentException('--limit must be 1..100');
            }
            $section = $this->option('section');
            switch ($action) {
                case 'brief':
                case 'brief-eval':
                    return $this->brief($briefs, $limit);
                case 'ingest':
                    $fixture = $this->option('fixture') ?: base_path('tests/Fixtures/storylens-rss-v1.json');
                    $this->info('Ingested '.$catalog->ingest(json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR)).' Articles');
                    break;
                case 'schema':
                    $timeline->bootstrap();
                    $this->info('Cassandra Story timeline schema ready');
                    break;
                case 'consume':
                    $consumer = (string) $this->option('consumer');
                    $count = ProjectionConsumeBudget::run($consumer, $limit, fn () => $pipeline->consume($section, $consumer, 1));
                    $this->info('Processed '.$count.' deliveries');
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

    private function brief(Briefs $briefs, int $limit): int
    {
        $generate = $this->argument('action') === 'brief';
        $id = $this->option('story');
        if (($id !== null && (! Str::isUuid($id) || $this->option('all')))
            || ($generate && $id === null && ! $this->option('all'))) {
            $this->error('Use --story=<uuid> or --all for brief generation.');

            return self::FAILURE;
        }
        try {
            $query = Story::query()->select(['_id', 'version']);
            if ($id !== null) {
                $query->where('_id', $id);
            } else {
                $query->latest()->orderByDesc('_id')->limit($generate ? Briefs::RECENT_LIMIT : $limit);
            }
            $stories = $query->get();
            if ($id !== null && $stories->isEmpty()) {
                $this->error('Story not found.');

                return self::FAILURE;
            }
            if ($generate) {
                $queued = $stories->filter(fn (Story $story, int $position) => $briefs->queue(
                    $story->id,
                    (int) ($story->version ?? 0),
                    $this->option('sync'),
                    $position,
                ))->count();
                $verb = $this->option('sync') ? 'Ran' : 'Queued';
                $this->info($verb.' brief generation for '.$queued.' of '.$stories->count().' Stories');

                return $queued === $stories->count() ? self::SUCCESS : self::FAILURE;
            }
            $rows = $stories->map(fn (Story $story) => $briefs->evaluate($story))->all();
            if ($this->option('json')) {
                $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Story', 'Version', 'Points', 'Evidence cited/sent', 'Invalid outputs', 'Accepted', 'Rejected'],
                    array_map(fn (array $row) => [
                        $row['story_id'], $row['story_version'], $row['points_count'], $row['evidence_cited'].'/'.$row['evidence_sent'],
                        sprintf('%.1f%% (%d/%d)', 100 * $row['invalid_output_rate'], $row['invalid_outputs'], $row['attempts']),
                        $row['accepted_count'], $row['rejected_count'],
                    ], $rows));
            }
        } catch (\Throwable) {
            $this->error('Story brief data is temporarily unavailable.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
