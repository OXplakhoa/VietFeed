<?php

namespace App\Moderation;

use App\Processing\StreamConsumer;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Bigint;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\Collection;
use Throwable;

/**
 * Moderation audit pipeline, same shape as the Story pipeline: MongoDB case/sanction documents hold
 * the canonical events (outbox) → Redis Stream → idempotent Cassandra projection (INSERT by primary
 * key), with the shared StreamConsumer's retries, DLQ and replay. Cassandra being down never blocks a
 * moderation action; `reemit()` republishes canonical history.
 */
final class ModerationAudit
{
    public const STREAM = 'moderation:events:v1';

    public const GROUP = 'moderation-audit-v1';

    public const DLQ = 'moderation:dead:audit:v1';

    public const ERRORS = 'moderation:errors:audit:v1';

    public const TABLE = 'moderation_events_by_case';

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(private CassandraClient $client) {}

    public function stream(): StreamConsumer
    {
        return new StreamConsumer(self::STREAM, self::GROUP, self::DLQ, self::ERRORS, (int) config('nosql.moderation_retry_ms', 1000));
    }

    public function bootstrap(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        $this->client->execute('CREATE TABLE IF NOT EXISTS '.$keyspace.'.'.self::TABLE.' (case_id uuid, occurred_at timestamp, event_id uuid, type text, actor_id text, case_version bigint, aggregate_version bigint, aggregate_type text, aggregate_id text, note text, report_id text, sanction_id text, comment_id bigint, comment_ref text, PRIMARY KEY ((case_id), occurred_at, event_id)) WITH CLUSTERING ORDER BY (occurred_at ASC, event_id ASC)');
        // Tables created earlier lack these columns: the 9b fix round's document version, and Gate 9.5's
        // string comment reference. Cassandra cannot change a column type, so `comment_id bigint` stays
        // for history and new events write `comment_ref text`.
        foreach (['aggregate_version bigint', 'comment_ref text'] as $column) {
            self::addColumn(fn () => $this->client->execute('ALTER TABLE '.$keyspace.'.'.self::TABLE.' ADD '.$column));
        }
    }

    /**
     * Run one idempotent `ALTER TABLE … ADD`: only Cassandra's "column already exists" answer is
     * tolerated; anything else (unreachable node, timeout, syntax) is rethrown so `schema` never
     * reports success without the column.
     */
    public static function addColumn(callable $alter): void
    {
        try {
            $alter();
        } catch (\RuntimeException $e) {
            for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                if (preg_match('/conflicts with an existing column|already exists/i', $error->getMessage())) {
                    return;
                }
            }
            throw $e;
        }
    }

    /** Best effort: the canonical copy is already in MongoDB; a failed publish is recovered by reemit. */
    public function publish(array $events): int
    {
        $published = 0;
        try {
            $stream = $this->stream();
            foreach ($events as $event) {
                $stream->publish(ModerationEvents::plain($event));
                $published++;
            }
        } catch (Throwable $e) {
            Log::warning('Moderation audit publish failed', ['exception_type' => $e::class]);
        }

        return $published;
    }

    /** Project one delivery. Throws for anything that is not byte-equal to the canonical Mongo event. */
    public function apply(array $event): bool
    {
        foreach (['event_id', 'case_id', 'aggregate_id'] as $field) {
            if (! is_string($event[$field] ?? null) || ! Str::isUuid($event[$field])) {
                throw new \InvalidArgumentException('Malformed moderation event');
            }
        }
        $canonical = $this->canonical($event['aggregate_type'] ?? '', $event['aggregate_id'], $event['event_id']);
        if ($canonical === null || $canonical !== $event) {
            throw new \InvalidArgumentException('Event does not match canonical moderation history');
        }
        $columns = [
            'case_id' => new Uuid($event['case_id']), 'occurred_at' => new Timestamp($event['occurred_at']),
            'event_id' => new Uuid($event['event_id']), 'type' => $event['type'], 'case_version' => isset($event['case_version']) ? new Bigint((int) $event['case_version']) : null,
            'aggregate_version' => new Bigint((int) $event['aggregate_version']),
            'aggregate_type' => $event['aggregate_type'], 'aggregate_id' => $event['aggregate_id'],
            'actor_id' => $event['actor_id'], 'note' => $event['note'], 'report_id' => $event['report_id'],
            'sanction_id' => $event['sanction_id'],
        ] + $this->commentColumns($event);
        $columns = array_filter($columns, fn ($value) => $value !== null);
        $keyspace = $this->client->keyspace();
        // Same primary key on every delivery: duplicates and replays overwrite the identical row.
        $this->client->execute('INSERT INTO '.$keyspace.'.'.self::TABLE.' ('.implode(', ', array_keys($columns)).') VALUES ('
            .implode(', ', array_fill(0, count($columns), '?')).')', array_values($columns))->asVoidResult();

        return true;
    }

    /**
     * Gate 9.5: a string comment id goes to `comment_ref text`; a legacy integer (an event recorded before
     * the cutover, or its `legacy_comment_id` after the import remap) to the historical `comment_id bigint`
     * with the same value it already had, so a replay never rewrites history.
     */
    private function commentColumns(array $event): array
    {
        $ref = $event['comment_id'] ?? null;
        // A numeric value is a legacy SQL id (written by the 9b import before the comment import ran).
        $isLegacy = is_int($ref) || (is_string($ref) && ctype_digit($ref));
        $legacy = $event['legacy_comment_id'] ?? ($isLegacy ? $ref : null);

        return [
            'comment_id' => $legacy === null ? null : new Bigint((int) $legacy),
            'comment_ref' => is_string($ref) && ! $isLegacy ? $ref : null,
        ];
    }

    public function consume(string $consumer, int $limit = 10): int
    {
        return $this->stream()->consume($consumer, $limit, fn (array $event) => $this->apply($event));
    }

    /** Canonical event as stored in MongoDB (plain array), or null. */
    public function canonical(string $aggregateType, string $aggregateId, string $eventId): ?array
    {
        $collection = match ($aggregateType) {
            'case' => 'moderation_cases',
            'sanction' => 'sanctions',
            default => null,
        };
        if ($collection === null) {
            return null;
        }
        $doc = $this->collection($collection)->findOne(['_id' => $aggregateId, 'events.event_id' => $eventId], ['projection' => ['events.$' => 1]]);

        return $doc ? ModerationEvents::plain($doc['events'][0]) : null;
    }

    /** All canonical events of one audit partition (case events + events of sanctions filed under it). */
    public function canonicalFor(string $caseId): array
    {
        $events = [];
        $case = $this->collection('moderation_cases')->findOne(['_id' => $caseId], ['projection' => ['events' => 1]]);
        foreach ($case['events'] ?? [] as $event) {
            $events[] = ModerationEvents::plain($event);
        }
        foreach ($this->collection('sanctions')->find(['audit_case_id' => $caseId], ['projection' => ['events' => 1]]) as $sanction) {
            foreach ($sanction['events'] ?? [] as $event) {
                $events[] = ModerationEvents::plain($event);
            }
        }
        usort($events, fn ($a, $b) => [$a['occurred_at'], $a['event_id']] <=> [$b['occurred_at'], $b['event_id']]);

        return $events;
    }

    /**
     * "Lịch sử quyết định" of one case: Cassandra rows when reachable.
     *
     * @return array{state: 'ready'|'partial'|'pending', events: list<array>, expected: int}
     */
    public function timeline(string $caseId): array
    {
        $expected = count($this->canonicalFor($caseId));
        try {
            $keyspace = $this->client->keyspace();
            $rows = $this->client->rows('SELECT case_id, occurred_at, event_id, type, actor_id, case_version, aggregate_version, aggregate_type, aggregate_id, note, comment_id, comment_ref FROM '
                .$keyspace.'.'.self::TABLE.' WHERE case_id = ?', [new Uuid($caseId)]);
        } catch (Throwable $e) {
            Log::warning('Moderation audit timeline unavailable', ['exception_type' => $e::class]);

            return ['state' => 'pending', 'events' => [], 'expected' => $expected];
        }
        $rows = array_values(array_map(function ($row) {
            $row = (array) $row;
            // Either reference shape: Gate 9.5 rows carry `comment_ref`, history rows only `comment_id bigint`.
            $legacy = $row['comment_id'] ?? null;
            $row['comment'] = $row['comment_ref'] ?? ($legacy === null ? null : (string) $legacy);

            return $row;
        }, $rows));

        return ['state' => count($rows) >= $expected ? 'ready' : 'partial', 'events' => $rows, 'expected' => $expected];
    }

    public function overview(): array
    {
        $stream = $this->stream();
        $dead = [];
        foreach ($stream->deadLetters() as $id => $entry) {
            $event = json_decode($entry['event'] ?? '', true);
            $dead[] = [
                'id' => $id, 'case' => is_array($event) ? ($event['case_id'] ?? null) : null,
                'type' => is_array($event) ? ($event['type'] ?? '—') : '—',
                'version' => is_array($event) ? ($event['aggregate_version'] ?? '—') : '—',
                'attempts' => $entry['attempts'] ?? null, 'has_error' => ! empty($entry['last_error']),
            ];
        }

        return ['lengths' => $stream->lengths(), 'pending' => $stream->pending(), 'dead' => $dead];
    }

    /** Republish canonical events (one case, or everything). Projection is idempotent, so this is safe. */
    public function reemit(?string $caseId = null): int
    {
        if ($caseId !== null) {
            $events = $this->canonicalFor($caseId);
            if ($events === [] && $this->collection('moderation_cases')->countDocuments(['_id' => $caseId]) === 0
                && $this->collection('sanctions')->countDocuments(['audit_case_id' => $caseId]) === 0) {
                throw new \InvalidArgumentException('Moderation case not found');
            }

            return $this->publishOrFail($events);
        }
        $count = 0;
        foreach (['moderation_cases', 'sanctions'] as $name) {
            foreach ($this->collection($name)->find(['events.0' => ['$exists' => true]], ['projection' => ['events' => 1]]) as $doc) {
                $count += $this->publishOrFail(array_map(fn ($e) => ModerationEvents::plain($e), $doc['events']));
            }
        }

        return $count;
    }

    private function publishOrFail(array $events): int
    {
        $stream = $this->stream();
        foreach ($events as $event) {
            $stream->publish($event);
        }

        return count($events);
    }

    private function collection(string $name): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
