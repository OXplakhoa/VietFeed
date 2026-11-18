<?php

namespace App\SourceHistory;

use App\Processing\StreamConsumer;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Bigint;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class SourceFetchHistory
{
    public const BY_SOURCE = 'source_fetches_by_source_day';

    public const GLOBAL = 'source_fetches_by_day';

    public const STREAM = 'sources:history:events:v1';

    public const DLQ = 'sources:history:dead:v1';

    public const ERRORS = 'sources:history:errors:v1';

    public const RETENTION_DAYS = 30;

    public const RETENTION_SECONDS = self::RETENTION_DAYS * 86400;

    private const METRICS = [
        'http_status', 'items_found', 'items_created', 'items_updated', 'valid_items', 'invalid_items',
        'missing_image_count', 'date_parse_error_count', 'duplicate_count', 'duration_ms',
    ];

    private const DATES = ['fetch_started_at', 'finished_at', 'created_at', 'updated_at'];

    public function __construct(private CassandraClient $client) {}

    public function stream(): StreamConsumer
    {
        return new StreamConsumer(self::STREAM, 'sources-history-v1', self::DLQ, self::ERRORS, (int) config('nosql.source_history_retry_ms', 1000));
    }

    public function bootstrap(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        $metrics = implode(', ', array_map(fn ($name) => "$name bigint", self::METRICS));
        $dates = implode(', ', array_map(fn ($name) => "$name timestamp", self::DATES));
        // Keep null-cell markers until any older, replayable snapshot has expired.
        $grace = self::RETENTION_SECONDS;
        foreach ([self::BY_SOURCE => 'source_id, day', self::GLOBAL => 'day'] as $table => $partition) {
            $this->client->execute("CREATE TABLE IF NOT EXISTS $keyspace.$table (
                source_id text, day text, started_at timestamp, attempt_id uuid, type text, status text,
                error_type text, error_message text, $dates, $metrics,
                PRIMARY KEY (($partition), started_at, attempt_id)
            ) WITH CLUSTERING ORDER BY (started_at DESC, attempt_id DESC) AND gc_grace_seconds = $grace");
        }
    }

    /** Cassandra is accessed by the consumer, never during an RSS request or fetch job. */
    public function publish(SourceFetchAttempt $attempt, int $revision, array $fields): void
    {
        try {
            foreach (self::DATES as $field) {
                if (($fields[$field] ?? null) instanceof DateTimeInterface) {
                    $fields[$field] = SourceFetchAttempt::timestamp($fields[$field]);
                }
            }
            $event = [
                'attempt_id' => $attempt->id,
                'source_id' => $attempt->sourceId,
                'type' => $attempt->type,
                'started_at' => $attempt->startedAt,
                'revision' => $revision,
                'created_at' => $attempt->startedAt,
                'updated_at' => SourceFetchAttempt::timestamp(now()),
            ] + $fields;
            $this->stream()->publish(array_map(fn ($value) => is_string($value) ? mb_scrub($value, 'UTF-8') : $value, $event));
        } catch (Throwable $e) {
            Log::warning('Source fetch history publish failed', ['exception_type' => $e::class]);
        }
    }

    public function consume(string $consumer, int $limit, ?callable $withinBudget = null): int
    {
        $stream = $this->stream();
        $project = function (array $event): void {
            try {
                $this->apply($event);
            } catch (Throwable $e) {
                Log::warning('Source fetch history projection failed', ['exception_type' => $e::class]);

                // The shared consumer puts this message in its retry and dead-letter metadata.
                throw new \RuntimeException('Source fetch history projection failed ('.$e::class.')', 0, $e);
            }
        };

        if ($withinBudget === null) {
            return $stream->consume($consumer, $limit, $project);
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Batch limit must be 1..100');
        }

        $count = 0;
        while ($count < $limit && $withinBudget() && $stream->consume($consumer, 1, $project) === 1) {
            $count++;
        }

        return $count;
    }

    /** Write one snapshot to both read models. Returns false if outside retention. */
    public function apply(array $event): bool
    {
        if (! Str::isUuid($event['attempt_id'] ?? null, 7)
            || ! is_string($event['source_id'] ?? null) || $event['source_id'] === ''
            || ! is_string($event['started_at'] ?? null) || $event['started_at'] === ''
            || ! is_int($event['revision'] ?? null) || $event['revision'] < 0
            || ! is_string($event['type'] ?? null) || ! is_string($event['status'] ?? null)) {
            throw new InvalidArgumentException('Malformed source fetch history event');
        }

        $at = Carbon::parse($event['started_at'])->utc();
        $ttl = self::RETENTION_SECONDS - max(0, now()->getTimestamp() - $at->getTimestamp());
        if ($ttl <= 0) {
            return false;
        }
        $columns = [
            'source_id' => $event['source_id'],
            'day' => $at->format('Y-m-d'),
            'started_at' => new Timestamp($at),
            'attempt_id' => new Uuid($event['attempt_id']),
            'type' => $event['type'],
            'status' => $event['status'],
        ];
        foreach (['error_type', 'error_message'] as $name) {
            if (isset($event[$name]) || (array_key_exists($name, $event) && in_array($event['status'], ['success', 'warning'], true))) {
                $columns[$name] = $event[$name];
            }
        }
        foreach (self::METRICS as $name) {
            if (isset($event[$name])) {
                $columns[$name] = new Bigint((int) $event[$name]);
            }
        }
        foreach (self::DATES as $name) {
            if (isset($event[$name])) {
                $columns[$name] = new Timestamp(Carbon::parse($event[$name]));
            }
        }

        // Both rows have identical keys on replay, and an older lifecycle snapshot cannot replace a newer one.
        $writeTimestamp = $at->getTimestampMs() * 1000 + $event['revision'];
        $keyspace = $this->client->keyspace();
        $names = implode(', ', array_keys($columns));
        $markers = implode(', ', array_fill(0, count($columns), '?'));
        foreach ([self::BY_SOURCE, self::GLOBAL] as $table) {
            $this->client->execute(
                "INSERT INTO $keyspace.$table ($names) VALUES ($markers) USING TTL $ttl AND TIMESTAMP $writeTimestamp",
                array_values($columns),
            )->asVoidResult();
        }

        return true;
    }

    /** Most recent rows within the retention window, or all rows over the requested UTC days. */
    public function forSource(string $sourceId, ?int $limit = null, int $days = self::RETENTION_DAYS + 1): Collection
    {
        if ($limit === null) {
            return $this->recent(self::BY_SOURCE, $sourceId, $days, null);
        }

        $keyspace = $this->client->keyspace();
        $day = now()->utc()->startOfDay();
        $buckets = [];
        for ($offset = 0; $offset < $days; $offset++, $day->subDay()) {
            $buckets[] = $day->format('Y-m-d');
        }
        $rows = collect();
        foreach (array_chunk($buckets, 7) as $chunk) {
            $markers = implode(', ', array_fill(0, count($chunk), '?'));
            $remaining = $limit - $rows->count();
            $batch = $this->client->rows("SELECT * FROM $keyspace.".self::BY_SOURCE
                ." WHERE source_id = ? AND day IN ($markers) PER PARTITION LIMIT $remaining", [$sourceId, ...$chunk]);
            $rows = $rows->concat(collect($batch)
                ->sortByDesc(fn ($row) => [$row['started_at'], (string) $row['attempt_id']])
                ->take($remaining)->map($this->viewRow(...)));
            if ($rows->count() === $limit) {
                break;
            }
        }

        return $rows;
    }

    /** A predicate keeps deleted Mongo Sources out of the visible global history. */
    public function global(int $limit, ?callable $include = null, int $days = self::RETENTION_DAYS + 1): Collection
    {
        return $this->recent(self::GLOBAL, null, $days, $limit, $include);
    }

    private function recent(string $table, ?string $sourceId, int $days, ?int $limit, ?callable $include = null): Collection
    {
        $keyspace = $this->client->keyspace();
        $day = now()->utc()->startOfDay();
        $rows = collect();
        for ($offset = 0; $offset < $days; $offset++, $day->subDay()) {
            $where = 'day = ?';
            $partition = [$day->format('Y-m-d')];
            if ($sourceId !== null) {
                $where .= ' AND source_id = ?';
                $partition[] = $sourceId;
            }
            $cursor = null;
            do {
                $size = $limit === null ? 100 : max(50, $limit - $rows->count());
                $page = $this->page($keyspace, $table, $where, $partition, $cursor, $size);
                foreach ($page as $row) {
                    if ($include === null || $include($row['source_id'])) {
                        $rows->push($this->viewRow($row));
                        if ($limit !== null && $rows->count() === $limit) {
                            return $rows;
                        }
                    }
                }
                $cursor = end($page);
            } while (count($page) === $size);
        }

        return $rows;
    }

    private function page(string $keyspace, string $table, string $where, array $partition, ?array $cursor, int $limit): array
    {
        if ($cursor !== null) {
            $where .= ' AND (started_at, attempt_id) < (?, ?)';
            $partition[] = new Timestamp(Carbon::parse($cursor['started_at']));
            $partition[] = new Uuid($cursor['attempt_id']);
        }

        return $this->client->rows("SELECT * FROM $keyspace.$table WHERE $where LIMIT $limit", $partition);
    }

    private function viewRow(array $row): object
    {
        $row['attempt_id'] = (string) $row['attempt_id'];
        $row['started_at'] = $row['fetch_started_at'];
        foreach (['started_at', 'finished_at', 'created_at', 'updated_at'] as $name) {
            $row[$name] = $row[$name] === null ? null : Carbon::parse($row[$name])->timezone(config('app.timezone'));
        }

        return (object) $row;
    }
}
