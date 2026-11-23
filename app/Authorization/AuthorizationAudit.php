<?php

namespace App\Authorization;

use App\Models\User;
use App\StoryProcessing\CassandraClient;
use Cassandra\Exception\ValueException;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Throwable;
use ValueError;

final class AuthorizationAudit
{
    public const BY_USER = 'authorization_audit_by_user';

    public const BY_DAY = 'authorization_audit_by_day';

    public const OUTBOX = 'authorization_audit_pending';

    public const USER_OUTBOX = 'authorization_audit_pending';

    public const MAX_ATTEMPTS = 5;

    public const MAX_DEAD_LETTER_BATCH = 500;

    private const RETRY_BUDGET_SECONDS = 35;

    private const RECENT_DAYS = 30;

    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(private CassandraClient $client) {}

    public function schema(): void
    {
        $keyspace = $this->client->keyspace();
        $this->client->execute("CREATE KEYSPACE IF NOT EXISTS $keyspace WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        $fields = 'occurred_at timestamp, event_id uuid, actor_id text, target_id text, before_state text, after_state text, reason text';
        $order = 'WITH CLUSTERING ORDER BY (occurred_at DESC, event_id DESC)';
        $this->client->execute('CREATE TABLE IF NOT EXISTS '.$keyspace.'.'.self::BY_USER." ($fields, PRIMARY KEY ((target_id), occurred_at, event_id)) $order");
        $this->client->execute('CREATE TABLE IF NOT EXISTS '.$keyspace.'.'.self::BY_DAY." (day text, $fields, PRIMARY KEY ((day), occurred_at, event_id)) $order");
    }

    public function event(string $actor, User|string $target, array $before, ?array $after, string $reason): array
    {
        return [
            'event_id' => (string) Str::uuid7(),
            'at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'actor' => $actor,
            'target' => $target instanceof User ? (string) $target->id : $target,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ];
    }

    /** Used for refused attempts and for events that must survive physical deletion. */
    public function enqueue(array $event): void
    {
        $this->collection(self::OUTBOX)->updateOne(
            ['_id' => $this->outboxId($event)],
            ['$setOnInsert' => ['event' => $event]],
            ['upsert' => true],
        );
    }

    public function record(array $event): void
    {
        $this->enqueue($event);
        $this->deliver($event);
    }

    public function recordBestEffort(array $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $e) {
            Log::warning('Authorization refusal could not be queued', ['event_id' => $event['event_id'], 'exception_type' => $e::class]);
        }
    }

    public function deliverGuard(array $event): void
    {
        if ($this->project($event, $event['event_id']) !== true) {
            return;
        }
        try {
            $this->acknowledgeEmbedded(SuperAdminGuard::COLLECTION, SuperAdminGuard::ID, $event);
        } catch (Throwable $e) {
            Log::warning('Authorization audit acknowledgement failed', ['event_id' => $event['event_id'], 'exception_type' => $e::class]);
        }
    }

    public function deliver(array $event, ?User $user = null): bool
    {
        if ($this->project($event, $event['event_id']) !== true) {
            return false;
        }

        try {
            if ($user === null) {
                $this->collection(self::OUTBOX)->deleteOne(['_id' => $event['event_id']]);
            } else {
                $this->acknowledgeEmbedded('users', $user->getRawOriginal('id') ?? $user->getRawOriginal('_id'), $event);
            }
        } catch (Throwable $e) {
            Log::warning('Authorization audit acknowledgement failed', ['event_id' => $event['event_id'], 'exception_type' => $e::class]);

            return false;
        }

        return true;
    }

    public function transferPending(User $user): void
    {
        $document = $this->collection('users')->findOne(['_id' => $user->getRawOriginal('id') ?? $user->getRawOriginal('_id')], ['projection' => [self::USER_OUTBOX => 1]]);
        foreach ($document[self::USER_OUTBOX] ?? [] as $event) {
            $this->enqueue((array) $event);
        }
    }

    public function retry(int $limit = 100): int
    {
        $deadline = hrtime(true) + self::RETRY_BUDGET_SECONDS * 1_000_000_000;
        $staged = $this->stageEmbedded(SuperAdminGuard::COLLECTION, $limit, $deadline);
        if ($staged < $limit && hrtime(true) < $deadline) {
            $this->stageEmbedded('users', $limit - $staged, $deadline);
        }
        if (hrtime(true) >= $deadline) {
            return 0;
        }
        $delivered = 0;
        $outbox = $this->collection(self::OUTBOX);
        foreach ($outbox->find(['dead_at' => null], ['sort' => ['attempts' => 1, '_id' => 1], 'limit' => $limit]) as $document) {
            if (hrtime(true) >= $deadline) {
                break;
            }

            $id = (string) $document['_id'];
            $result = $this->project((array) ($document['event'] ?? []), $id, $deadline);
            if ($result === null) {
                break;
            }
            if ($result) {
                $outbox->deleteOne(['_id' => $id]);
                $delivered++;

                continue;
            }

            $attempts = (int) ($document['attempts'] ?? 0) + 1;
            $set = ['attempts' => $attempts];
            if ($attempts >= self::MAX_ATTEMPTS) {
                $set['dead_at'] = new UTCDateTime(now());
            }
            $outbox->updateOne(
                ['_id' => $id, 'dead_at' => null, 'attempts' => $document['attempts'] ?? null],
                ['$set' => $set],
            );
        }

        return $delivered;
    }

    public function deadLetters(?string $userId, int $limit = 50, int $offset = 0): array
    {
        $filter = ['dead_at' => ['$ne' => null]];
        if ($userId !== null) {
            $filter['event.target'] = $userId;
        }

        return $this->collection(self::OUTBOX)->find($filter, [
            'sort' => ['dead_at' => -1, 'attempts' => -1, '_id' => -1],
            'limit' => max(1, min($limit, self::MAX_DEAD_LETTER_BATCH)),
            'skip' => $offset,
        ])->toArray();
    }

    public function replay(string $id): bool
    {
        return $this->collection(self::OUTBOX)->updateOne(
            ['_id' => $id, 'dead_at' => ['$ne' => null]],
            ['$unset' => ['dead_at' => '', 'attempts' => '']],
        )->getModifiedCount() === 1;
    }

    public function replayAll(int $limit = 100): int
    {
        $outbox = $this->collection(self::OUTBOX);
        $updates = [];
        foreach ($outbox->find(['dead_at' => ['$ne' => null]], [
            'sort' => ['dead_at' => 1, 'attempts' => 1, '_id' => 1],
            'limit' => max(1, min($limit, self::MAX_DEAD_LETTER_BATCH)),
            'projection' => ['dead_at' => 1],
        ]) as $entry) {
            $updates[] = ['updateOne' => [
                ['_id' => $entry['_id'], 'dead_at' => $entry['dead_at']],
                ['$unset' => ['dead_at' => '', 'attempts' => '']],
            ]];
        }

        return $updates === [] ? 0 : $outbox->bulkWrite($updates)->getModifiedCount();
    }

    private function stageEmbedded(string $collection, int $limit, int $deadline): int
    {
        $staged = 0;
        foreach ($this->collection($collection)->find(
            [self::USER_OUTBOX.'.0' => ['$exists' => true]], ['limit' => $limit, 'projection' => [self::USER_OUTBOX => 1]],
        ) as $document) {
            foreach ($document[self::USER_OUTBOX] as $event) {
                if (hrtime(true) >= $deadline) {
                    return $staged;
                }
                $this->enqueue((array) $event);
                $this->acknowledgeEmbedded($collection, $document['_id'], $event);
                if (++$staged === $limit) {
                    return $staged;
                }
            }
        }

        return $staged;
    }

    private function acknowledgeEmbedded(string $collection, mixed $id, mixed $event): void
    {
        $match = is_array($event) && is_string($event['event_id'] ?? null)
            ? ['event_id' => $event['event_id']]
            : ['$eq' => $event];
        $this->collection($collection)->updateOne(['_id' => $id], ['$pull' => [self::USER_OUTBOX => $match]]);
    }

    private function outboxId(array $event): string
    {
        return is_string($event['event_id'] ?? null) ? $event['event_id'] : hash('sha256', serialize($event));
    }

    /** true = delivered, false = invalid event, null = delivery unavailable or out of time. */
    private function project(array $event, string $id, ?int $deadline = null): ?bool
    {
        try {
            $values = $this->encode($event, $id);
        } catch (InvalidArgumentException|JsonException|ValueError|ValueException $e) {
            Log::warning('Invalid authorization audit event', ['event_id' => $id, 'exception_type' => $e::class]);

            return false;
        }

        try {
            return $this->append($values, substr($event['at'], 0, 10), $deadline) ? true : null;
        } catch (Throwable $e) {
            // The statements are fixed and the event is already validated and encoded.
            // A projection failure can affect every event, so leave it pending.
            Log::warning('Authorization audit delivery unavailable', ['event_id' => $id, 'exception_type' => $e::class]);

            return null;
        }
    }

    private function encode(array $event, string $id): array
    {
        if (($event['event_id'] ?? null) !== $id || ! Str::isUuid($id)
            || ! is_string($event['at'] ?? null)
            || ! is_string($event['actor'] ?? null)
            || ! is_string($event['target'] ?? null)
            || ! is_string($event['reason'] ?? null)
            || ! is_array($event['before'] ?? null)
            || ! array_key_exists('after', $event) || ($event['after'] !== null && ! is_array($event['after']))) {
            throw new InvalidArgumentException('Malformed authorization audit event.');
        }
        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $event['at'], new DateTimeZone('UTC'));
        if ($at === false || $at->format('Y-m-d\TH:i:s.v\Z') !== $event['at']) {
            throw new InvalidArgumentException('Invalid authorization audit timestamp.');
        }

        return [
            $event['target'],
            new Timestamp($at),
            new Uuid($id),
            $event['actor'],
            json_encode($event['before'], JSON_THROW_ON_ERROR),
            json_encode($event['after'], JSON_THROW_ON_ERROR),
            $event['reason'],
        ];
    }

    public function recent(): ?array
    {
        return $this->read(function (string $keyspace): array {
            $rows = [];
            $today = now()->utc()->startOfDay();
            for ($offset = 0; $offset < self::RECENT_DAYS && count($rows) < 50; $offset++) {
                $day = $today->copy()->subDays($offset)->toDateString();
                array_push($rows, ...$this->client->rows('SELECT * FROM '.$keyspace.'.'.self::BY_DAY.' WHERE day = ? LIMIT '.(50 - count($rows)), [$day]));
            }

            return $rows;
        });
    }

    public function forUser(string $userId): ?array
    {
        return $this->read(fn (string $keyspace) => $this->client->rows(
            'SELECT * FROM '.$keyspace.'.'.self::BY_USER.' WHERE target_id = ? LIMIT 50', [$userId],
        ));
    }

    private function read(callable $query): ?array
    {
        try {
            return array_map(fn ($row) => (array) $row, $query($this->client->keyspace()));
        } catch (Throwable $e) {
            Log::warning('Authorization audit unavailable', ['exception_type' => $e::class]);

            return null;
        }
    }

    private function append(array $values, string $day, ?int $deadline): bool
    {
        if ($deadline !== null && hrtime(true) >= $deadline) {
            return false;
        }
        $keyspace = $this->client->keyspace();
        $columns = 'target_id, occurred_at, event_id, actor_id, before_state, after_state, reason';
        $this->client->execute('INSERT INTO '.$keyspace.'.'.self::BY_USER." ($columns) VALUES (?, ?, ?, ?, ?, ?, ?)", $values)->asVoidResult();
        // A replay keeps the original retention deadline, even after a long outage.
        $expires = (new DateTimeImmutable($day.'T00:00:00Z'))->modify('+'.self::RECENT_DAYS.' days');
        $ttl = $expires->getTimestamp() - now()->getTimestamp();
        if ($ttl > 0) {
            if ($deadline !== null && hrtime(true) >= $deadline) {
                return false;
            }
            $this->client->execute('INSERT INTO '.$keyspace.'.'.self::BY_DAY." (day, $columns) VALUES (?, ?, ?, ?, ?, ?, ?, ?) USING TTL $ttl",
                [$day, ...$values])->asVoidResult();
        }

        return true;
    }

    private function collection(string $name): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
