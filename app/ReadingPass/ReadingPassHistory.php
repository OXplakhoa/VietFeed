<?php

namespace App\ReadingPass;

use App\Processing\StreamConsumer;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Boolean;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Unlock history (spec §9.2 "reading/unlock history by user and month"): MongoDB ledger → Redis Stream →
 * idempotent Cassandra inserts through the shared StreamConsumer (retries, DLQ, replay). The event is a pure
 * function of the granted ledger document, so `reemit` republishes byte-identical events.
 */
final class ReadingPassHistory
{
    public const STREAM = 'reading-pass:events:v1';

    public const GROUP = 'reading-pass-history-v1';

    public const DLQ = 'reading-pass:dead:history:v1';

    public const ERRORS = 'reading-pass:errors:history:v1';

    public const BY_USER = 'unlocks_by_user_month';

    public const BY_SESSION = 'unlocks_by_session_day';

    /** Guest history follows the 30-day guest retention of the ledger. */
    public const GUEST_TTL = 30 * 86400;

    public function __construct(private CassandraClient $client) {}

    public function stream(): StreamConsumer
    {
        return new StreamConsumer(self::STREAM, self::GROUP, self::DLQ, self::ERRORS, (int) config('nosql.reading_pass_retry_ms', 1000));
    }

    public function bootstrap(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        foreach ([[self::BY_USER, 'user_id', 'year_month'], [self::BY_SESSION, 'session_hash', 'unlock_day']] as [$table, $id, $bucket]) {
            $this->client->execute("CREATE TABLE IF NOT EXISTS $keyspace.$table (
                $id text, $bucket text, unlocked_at timestamp, unlock_id uuid,
                article_id text, tier text, origin text, backfill boolean,
                PRIMARY KEY (($id, $bucket), unlocked_at, unlock_id)
            ) WITH CLUSTERING ORDER BY (unlocked_at DESC, unlock_id ASC)");
        }
    }

    /** Event for one granted ledger document. */
    public static function event(array $doc): array
    {
        return [
            'event_id' => (string) $doc['_id'],
            'type' => ($doc['origin'] ?? 'unlock') === 'transfer' ? 'UNLOCK_TRANSFERRED' : 'UNLOCKED',
            'aggregate_id' => (string) $doc['_id'],
            'aggregate_version' => 1,
            'user_id' => $doc['user_id'] ?? null,
            'session_hash' => ($doc['user_id'] ?? null) === null ? ($doc['session_hash'] ?? null) : null,
            'article_id' => (string) $doc['article_id'],
            'tier' => $doc['tier'] ?? null,
            'origin' => $doc['origin'] ?? 'unlock',
            'occurred_at' => UnlockLedger::carbon($doc['unlocked_at'])->format('Y-m-d\TH:i:s.v\Z'),
            'backfill' => in_array($doc['origin'] ?? null, ['import', 'seed'], true),
        ];
    }

    /** Best effort: the ledger is already durable, `reemit` recovers a lost publish. */
    public function publish(array $doc): bool
    {
        try {
            $this->stream()->publish(self::event($doc));

            return true;
        } catch (Throwable $e) {
            Log::warning('Reading Pass history publish failed', ['exception_type' => $e::class]);

            return false;
        }
    }

    public function apply(array $event): void
    {
        $valid = is_string($event['event_id'] ?? null) && Str::isUuid($event['event_id'])
            && is_string($event['article_id'] ?? null) && $event['article_id'] !== ''
            && is_string($event['occurred_at'] ?? null)
            && (is_string($event['user_id'] ?? null) xor is_string($event['session_hash'] ?? null));
        if (! $valid) {
            throw new \InvalidArgumentException('Malformed Reading Pass event');
        }
        $time = Carbon::parse($event['occurred_at'])->utc();
        $keyspace = $this->client->keyspace();
        $values = [new Timestamp($time), new Uuid($event['event_id']), $event['article_id'], $event['tier'] ?? null, $event['origin'] ?? null, new Boolean((bool) ($event['backfill'] ?? false))];
        if (is_string($event['user_id'] ?? null)) {
            $this->client->execute("INSERT INTO $keyspace.".self::BY_USER.' (user_id, year_month, unlocked_at, unlock_id, article_id, tier, origin, backfill) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$event['user_id'], $time->format('Y-m'), ...$values])->asVoidResult();

            return;
        }
        $ttl = self::GUEST_TTL - max(0, now()->getTimestamp() - $time->getTimestamp());
        if ($ttl <= 0) {
            return; // Already past guest retention: nothing to keep.
        }
        // Same primary key on every delivery: duplicates and replays overwrite the identical row.
        $this->client->execute("INSERT INTO $keyspace.".self::BY_SESSION.' (session_hash, unlock_day, unlocked_at, unlock_id, article_id, tier, origin, backfill) VALUES (?, ?, ?, ?, ?, ?, ?, ?) USING TTL '.(int) $ttl,
            [$event['session_hash'], $time->format('Y-m-d'), ...$values])->asVoidResult();
    }

    public function consume(string $consumer, int $limit = 10): int
    {
        return $this->stream()->consume($consumer, $limit, fn (array $event) => $this->apply($event));
    }

    /** @return array{published: int, failed: int} */
    public function reemit(UnlockLedger $ledger, ?string $userId = null): array
    {
        $counts = ['published' => 0, 'failed' => 0];
        $filter = ['state' => UnlockLedger::GRANTED] + ($userId === null ? [] : ['user_id' => $userId]);
        foreach ($ledger->collection()->find($filter, ['sort' => ['unlocked_at' => 1, '_id' => 1]]) as $doc) {
            $counts[$this->publish($doc) ? 'published' : 'failed']++;
        }

        return $counts;
    }

    /** One user's unlocks in one UTC month, newest first. */
    public function forUserMonth(string $userId, string $yearMonth): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $yearMonth)) {
            throw new \InvalidArgumentException('--month must be YYYY-MM');
        }
        $keyspace = $this->client->keyspace();
        $rows = $this->client->rows("SELECT unlocked_at, unlock_id, article_id, tier, origin, backfill FROM $keyspace.".self::BY_USER.' WHERE user_id = ? AND year_month = ?', [$userId, $yearMonth]);

        return array_map(fn ($row) => self::row((array) $row), $rows);
    }

    /** One guest session's unlocks in one UTC day, newest first. */
    public function forSessionDay(string $sessionHash, string $day): array
    {
        $keyspace = $this->client->keyspace();
        $rows = $this->client->rows("SELECT unlocked_at, unlock_id, article_id, tier, origin, backfill FROM $keyspace.".self::BY_SESSION.' WHERE session_hash = ? AND unlock_day = ?', [$sessionHash, $day]);

        return array_map(fn ($row) => self::row((array) $row), $rows);
    }

    private static function row(array $row): array
    {
        $at = $row['unlocked_at'];

        return [
            'unlocked_at' => ($at instanceof \DateTimeInterface ? Carbon::instance($at) : Carbon::parse((string) $at))->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'unlock_id' => (string) $row['unlock_id'],
            'article_id' => $row['article_id'],
            'tier' => $row['tier'],
            'origin' => $row['origin'],
            'backfill' => (bool) $row['backfill'],
        ];
    }
}
