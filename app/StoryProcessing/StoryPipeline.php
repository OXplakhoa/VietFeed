<?php

namespace App\StoryProcessing;

use App\Models\Story;
use App\Processing\StreamConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class StoryPipeline
{
    public const OVERVIEW_STORY_LIMIT = 50;

    public const STREAM = 'stories:events:v1';

    public function __construct(private StoryTimeline $timeline, private StoryGraph $graph) {}

    public function stream(string $section): StreamConsumer
    {
        if (! in_array($section, ['timeline', 'graph'], true)) {
            throw new \InvalidArgumentException('Section must be timeline or graph');
        }

        return new StreamConsumer(self::STREAM, 'story-'.$section.'-v1', 'stories:dead:'.$section.':v1', 'stories:errors:'.$section.':v1', (int) config('nosql.story_retry_ms', 1000), deleteAfterAck: false);
    }

    public function publish(array $event): string
    {
        return $this->stream('timeline')->publish($event);
    }

    public function apply(string $section, array $event): bool
    {
        $this->stream($section);
        $story = Story::findOrFail($event['aggregate_id'] ?? '');
        $canonical = collect($story->events ?? [])->firstWhere('event_id', $event['event_id'] ?? '');
        if ($canonical === null || $canonical !== $event) {
            throw new \InvalidArgumentException('Event does not match canonical Story history');
        }
        $version = $event['aggregate_version'];
        $current = $story->processing[$section]['projected_version'] ?? 0;
        if ($version <= $current) {
            return false;
        }
        try {
            if ($section === 'timeline') {
                $this->timeline->project($story, $version);
            } else {
                $this->graph->project($story, $version);
            }
        } catch (\Throwable $e) {
            $this->state($story, $section, $version, failed: true);
            throw $e;
        }
        $this->state($story, $section, $version);

        return true;
    }

    private function state(Story $story, string $section, int $version, bool $failed = false): void
    {
        $path = 'processing.'.$section;
        // Both the monotonic guard and readiness use the CURRENT Mongo document.
        $filter = ['_id' => $story->id, '$expr' => ['$lt' => [['$ifNull' => ['$'.$path.'.projected_version', 0]], $version]]];
        $collection = DB::connection('mongodb')->getMongoDB()->selectCollection('stories');
        if ($failed) {
            $filter['$expr'] = ['$and' => [$filter['$expr'], ['$lte' => [['$ifNull' => ['$'.$path.'.failed_version', 0]], $version]]]];
            $collection->updateOne($filter, ['$set' => [
                $path.'.state' => 'failed', $path.'.error' => 'Projection failed; inspect consumer DLQ', $path.'.failed_version' => $version,
            ]]);

            return;
        }
        $newerFailure = ['$gt' => [['$ifNull' => ['$'.$path.'.failed_version', 0]], $version]];
        $collection->updateOne($filter, [
            ['$set' => [
                $path.'.projected_version' => $version,
                $path.'.state' => ['$cond' => [$newerFailure, 'failed', ['$cond' => [['$eq' => ['$version', $version]], 'ready', 'pending']]]],
                $path.'.error' => ['$cond' => [$newerFailure, '$'.$path.'.error', '$$REMOVE']],
                $path.'.failed_version' => ['$cond' => [$newerFailure, '$'.$path.'.failed_version', '$$REMOVE']],
            ]],
        ]);
    }

    public static function sections(Story $story): array
    {
        $sections = $story->processing ?? [];
        foreach (['timeline', 'graph'] as $section) {
            $sections[$section] = array_replace(['state' => 'pending', 'projected_version' => 0], $sections[$section] ?? []);
            if ($sections[$section]['state'] === 'ready' && $sections[$section]['projected_version'] !== ($story->version ?? 0)) {
                $sections[$section]['state'] = 'pending';
            }
        }

        return $sections;
    }

    public function consume(string $section, string $consumer, int $limit = 10): int
    {
        return $this->stream($section)->consume($consumer, $limit, fn (array $event) => $this->apply($section, $event));
    }

    public function inspect(string $section, ?string $storyId = null): array
    {
        $stream = $this->stream($section);
        $stories = $storyId ? Story::where('_id', $storyId)->get() : Story::whereNotNull('story_key')->get();

        return ['section' => $section, 'lengths' => $stream->lengths(), 'pending_entries' => $stream->pending(), 'dead_letter_entries' => $stream->deadLetters(), 'stories' => $stories->map(fn (Story $story) => ['id' => $story->id, 'version' => $story->version ?? 0, 'processing' => self::sections($story)])->all()];
    }

    /** Operational snapshot; stream inspection may initialize consumer groups at ID 0. */
    public function overview(): array
    {
        $sections = [];
        foreach (['timeline', 'graph'] as $section) {
            $stream = $this->stream($section);
            $inspection = ['lengths' => $stream->lengths(), 'pending_entries' => $stream->pending(), 'dead_letter_entries' => $stream->deadLetters()];
            $dead = [];
            foreach ($inspection['dead_letter_entries'] as $id => $entry) {
                $event = json_decode($entry['event'] ?? '', true);
                $dead[] = [
                    'id' => $id, 'story' => $event['aggregate_id'] ?? null,
                    'type' => $event['type'] ?? '—', 'version' => $event['aggregate_version'] ?? '—',
                    'attempts' => $entry['attempts'] ?? null,
                    'has_error' => ! empty($entry['last_error']),
                ];
            }
            $sections[$section] = [
                'lengths' => $inspection['lengths'], 'pending' => $inspection['pending_entries'], 'dead' => $dead,
            ];
        }
        $stories = Story::query()->select(['_id', 'title', 'version', 'processing', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('_id')->limit(self::OVERVIEW_STORY_LIMIT)->get()->map(fn (Story $story) => [
                'id' => $story->id, 'title' => $story->title, 'version' => $story->version ?? 0,
                'timeline' => self::sections($story)['timeline']['state'],
                'graph' => self::sections($story)['graph']['state'],
            ])->all();

        $titles = array_column($stories, 'title', 'id');
        foreach ($sections as &$section) {
            foreach ($section['dead'] as &$entry) {
                $entry['story_title'] = $titles[$entry['story']] ?? 'Story ngoài danh sách mới nhất';
            }
            unset($entry);
        }
        unset($section);

        return ['sections' => $sections, 'stories' => $stories, 'story_limit' => self::OVERVIEW_STORY_LIMIT];
    }

    /** Pause consumers/writers first. Mongo history is the durable rebuild source. */
    public function reemit(?string $storyId = null): int
    {
        $stories = $storyId ? Story::where('_id', $storyId)->get() : Story::whereNotNull('story_key')->get();
        if ($storyId && $stories->isEmpty()) {
            throw new \InvalidArgumentException('Story not found');
        }
        // Reset all affected graph markers before any Mongo state change or publish.
        $this->graph->resetForRebuild($stories->modelKeys());
        $count = 0;
        foreach ($stories as $story) {
            // An operator rebuild deliberately resets progress. Refuse a changed snapshot.
            $reset = [];
            $errors = [];
            foreach (['timeline', 'graph'] as $section) {
                $reset['processing.'.$section.'.state'] = 'pending';
                $reset['processing.'.$section.'.projected_version'] = 0;
                $errors['processing.'.$section.'.error'] = '';
                $errors['processing.'.$section.'.failed_version'] = '';
            }
            $result = DB::connection('mongodb')->getMongoDB()->selectCollection('stories')->updateOne(
                ['_id' => $story->id, 'version' => $story->version ?? ['$exists' => false]],
                ['$set' => $reset, '$unset' => $errors],
            );
            if ($result->getMatchedCount() !== 1) {
                throw new \RuntimeException('Story changed during rebuild; pause writers and retry reemit');
            }
            foreach ($story->events ?? [] as $event) {
                $this->publish($event);
                $count++;
            }
        }

        return $count;
    }

    public function assertSafeTestStores(): void
    {
        if (! app()->environment('testing')) {
            return;
        }
        if (file_exists(base_path('bootstrap/cache/config.php'))
            || DB::connection('mongodb')->getDatabaseName() !== 'vietfeed_test'
            || config('database.default') !== 'mongodb'
            || array_keys(config('database.connections')) !== ['mongodb']
            || config('nosql.cassandra.keyspace') !== 'vietfeed_test'
            || Redis::connection('nosql')->client()->getDbNum() !== 15) {
            throw new \RuntimeException('Refusing StoryLens test command without isolated stores and live Redis DB15');
        }
    }
}
