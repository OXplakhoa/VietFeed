<?php

namespace App\Interaction;

use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Bigint;
use Cassandra\Value\Boolean;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use Illuminate\Support\Carbon;

final class InteractionActivity
{
    public const BY_USER = 'activity_by_user_month';

    public const BY_ARTICLE = 'activity_by_article_day';

    public function __construct(private CassandraClient $client) {}

    public function bootstrap(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        foreach ($this->partitions() as [$table, $id, $bucket]) {
            $this->client->execute("CREATE TABLE IF NOT EXISTS $keyspace.$table (
                user_id text, article_id text, $bucket text, occurred_at timestamp, event_id uuid,
                type text, aggregate_id uuid, aggregate_version bigint, backfill boolean,
                PRIMARY KEY (($id, $bucket), occurred_at, event_id)
            ) WITH CLUSTERING ORDER BY (occurred_at DESC, event_id ASC)");
        }
    }

    public function apply(InteractionEvent $event): void
    {
        $e = $event->data;
        $time = Carbon::parse($e['occurred_at'])->utc();
        $keyspace = $this->client->keyspace();
        foreach ($this->partitions() as [$table, , $bucket, $format]) {
            // A retry after either insert writes the same primary key and payload.
            $this->client->execute(
                "INSERT INTO $keyspace.$table (user_id, article_id, $bucket, occurred_at, event_id, type, aggregate_id, aggregate_version, backfill)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $e['user_id'], $e['article_id'], $time->format($format), new Timestamp($time), new Uuid($e['event_id']),
                    $e['type'], new Uuid($e['aggregate_id']), new Bigint($e['aggregate_version']), new Boolean($e['backfill'] ?? false),
                ],
            )->asVoidResult();
        }
    }

    private function partitions(): array
    {
        return [
            [self::BY_USER, 'user_id', 'year_month', 'Y-m'],
            [self::BY_ARTICLE, 'article_id', 'activity_day', 'Y-m-d'],
        ];
    }
}
