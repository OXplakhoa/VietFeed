<?php

namespace App\StoryProcessing;

use App\Models\Article;
use App\Models\Story;
use Cassandra\Value\Bigint;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;

class StoryTimeline
{
    public function __construct(private CassandraClient $client) {}

    public function bootstrap(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        $this->client->execute("CREATE TABLE IF NOT EXISTS $keyspace.story_events_by_story (story_id uuid, occurred_at timestamp, event_id uuid, aggregate_version bigint, type text, article_id uuid, PRIMARY KEY ((story_id), occurred_at, event_id)) WITH CLUSTERING ORDER BY (occurred_at DESC, event_id DESC)");
    }

    /** Reapply the durable prefix, so receiving a newer version first loses no history. */
    public function project(Story $story, int $version): void
    {
        $keyspace = $this->client->keyspace();
        $events = collect($story->events ?? [])->where('aggregate_version', '<=', $version);
        $ids = $events->pluck('article_id')->unique()->all();
        if (Article::whereIn('_id', $ids)->count() !== count($ids)) {
            throw new \RuntimeException('Timeline projection missing canonical Articles');
        }
        foreach ($events as $event) {
            $this->client->execute("INSERT INTO $keyspace.story_events_by_story (story_id, occurred_at, event_id, aggregate_version, type, article_id) VALUES (?, ?, ?, ?, ?, ?)", [
                new Uuid($story->id), new Timestamp($event['occurred_at']), new Uuid($event['event_id']),
                new Bigint($event['aggregate_version']), $event['type'], new Uuid($event['article_id']),
            ])->asVoidResult();
        }
    }

    public function events(string $storyId): array
    {
        $keyspace = $this->client->keyspace();

        return $this->client->rows("SELECT story_id, occurred_at, event_id, aggregate_version, type, article_id FROM $keyspace.story_events_by_story WHERE story_id = ?", [new Uuid($storyId)]);
    }
}
