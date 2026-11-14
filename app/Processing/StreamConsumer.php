<?php

namespace App\Processing;

use App\Interaction\RedisResult;
use Illuminate\Support\Facades\Redis;
use Throwable;

class StreamConsumer
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly string $stream, private readonly string $groupName, private readonly string $dlq, private readonly string $errors, private readonly int $retryMilliseconds = 1000, private readonly int $pendingPageSize = 1000, private readonly bool $deleteAfterAck = true)
    {
        if ($pendingPageSize < 1 || $pendingPageSize > 1000) {
            throw new \InvalidArgumentException('Pending page size must be 1..1000');
        }
        if ($retryMilliseconds < 1) {
            throw new \InvalidArgumentException('Retry delay must be positive');
        }
    }

    private function redis(): \Redis
    {
        return Redis::connection('nosql')->client();
    }

    private function group(): void
    {
        try {
            RedisResult::run('Stream group CREATE', fn () => $this->redis()->xGroup('CREATE', $this->stream, $this->groupName, '0', true), fn ($result) => $result === true);
        } catch (\RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    // Raw envelopes are accepted so malformed producer input is inspectable in DLQ.
    public function publish(array $event): string
    {
        return RedisResult::run('Stream XADD', fn () => $this->redis()->xAdd($this->stream, '*', ['event' => json_encode($event, JSON_THROW_ON_ERROR)]), fn ($result) => is_string($result) && preg_match('/^\d+-\d+$/D', $result));
    }

    /** Receive one leased delivery. UnACKed work becomes eligible after backoff. */
    public function receive(string $consumer): ?array
    {
        if ($consumer === '') {
            throw new \InvalidArgumentException('Consumer name is required');
        }
        $this->group();
        $redis = $this->redis();
        foreach ($this->recoverablePending() as [$id, $owner, $idle, $attempts]) {
            $delay = $this->retryMilliseconds * (2 ** min(4, $attempts - 1));
            if ($idle < $delay) {
                continue;
            }
            // Check payload and claim in one Lua turn: XCLAIM itself may silently
            // remove missing payloads from the PEL, leaving no reason to inspect.
            $terminal = $attempts >= self::MAX_ATTEMPTS;
            $claimed = RedisResult::run("Stream claim $id", fn () => Redis::connection('nosql')->eval(<<<'LUA'
local entry = redis.call('XRANGE', KEYS[1], ARGV[3], ARGV[3])
if #entry == 0 then return {0} end
local event
local fields = entry[1][2]
for i = 1, #fields, 2 do if fields[i] == 'event' then event = fields[i+1] end end
if not event then return {0} end
local claimed
if ARGV[5] == '1' then
    claimed = redis.call('XCLAIM', KEYS[1], ARGV[1], ARGV[2], ARGV[4], ARGV[3], 'JUSTID')
else
    claimed = redis.call('XCLAIM', KEYS[1], ARGV[1], ARGV[2], ARGV[4], ARGV[3])
end
if #claimed == 0 then return {} end
return {1, event}
LUA, 1, $this->stream, $this->groupName, $consumer, $id, $delay, $terminal ? '1' : '0'), fn ($result) => is_array($result));
            if ($claimed === []) {
                continue;
            }
            if ($claimed[0] === 0) {
                $this->deadLetter(['id' => $id, 'event' => '', 'attempts' => $attempts], 'Stream payload missing while pending');

                continue;
            }
            $delivery = ['id' => $id, 'event' => $claimed[1], 'attempts' => $terminal ? self::MAX_ATTEMPTS : $attempts + 1];
            if ($terminal) {
                $error = RedisResult::run('Stream terminal HGET', fn () => $redis->hGet($this->errors, $id), fn ($value) => $value === false || is_string($value)) ?: 'Consumer interrupted on final attempt';
                $this->deadLetter($delivery, $error);

                continue;
            }

            return $delivery;
        }

        // phpredis returns false for both an empty read and some server errors;
        // RedisResult also checks native error state before accepting false.
        $streams = RedisResult::run('Stream XREADGROUP',
            fn () => $redis->xReadGroup($this->groupName, $consumer, [$this->stream => '>'], 1),
            fn ($result) => $result === false || is_array($result));
        foreach ($streams ?: [] as $entries) {
            foreach ($entries as $id => $fields) {
                if (! array_key_exists('event', $fields)) {
                    $this->deadLetter(['id' => $id, 'event' => '', 'attempts' => 1], 'Stream payload missing while pending');

                    continue;
                }

                return ['id' => $id, 'event' => $fields['event'], 'attempts' => 1];
            }
        }

        return null;
    }

    public function consume(string $consumer, int $limit = 1, ?callable $handler = null): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Batch limit must be 1..100');
        }
        $count = 0;
        while ($count < $limit && ($delivery = $this->receive($consumer)) !== null) {
            $count++;
            try {
                $event = json_decode($delivery['event'], true, flags: JSON_THROW_ON_ERROR);
                if ($handler) {
                    $handler($event);
                } else {
                    throw new \LogicException('Stream handler required');
                }
            } catch (Throwable $e) {
                // Operational error text only; do not embed credentials in handler errors.
                $error = substr($e->getMessage(), 0, 1000);
                if ($delivery['attempts'] >= self::MAX_ATTEMPTS) {
                    $this->deadLetter($delivery, $error);
                } else {
                    // Recording failure propagates without ACK: keep this lease and
                    // its PEL attempt count, so recovery still stops at five.
                    RedisResult::run("Stream retry HSET {$delivery['id']}",
                        fn () => $this->redis()->hSet($this->errors, $delivery['id'], $error),
                        fn ($result) => $result === 0 || $result === 1);
                }

                continue;
            }
            // ACK only after the idempotent projection succeeds. A process interruption
            // before this point leaves the entry in the PEL for another consumer.
            $this->acknowledge($delivery['id']);
        }

        return $count;
    }

    public function acknowledge(string $id): void
    {
        RedisResult::run("Stream acknowledge $id", fn () => Redis::connection('nosql')->eval(<<<'LUA'
local pending = redis.call('XPENDING', KEYS[1], ARGV[1], ARGV[2], ARGV[2], 1)
if #pending == 0 then return 0 end
-- Cleanup can fail with WRONGTYPE; do it while the input is still pending.
redis.call('HDEL', KEYS[2], ARGV[2])
if redis.call('XACK', KEYS[1], ARGV[1], ARGV[2]) ~= 1 then return redis.error_reply('ACK failed') end
local deleted = 0
if ARGV[6] == '1' then deleted = redis.call('XDEL', KEYS[1], ARGV[2]) end
if deleted ~= 0 and deleted ~= 1 then return redis.error_reply('XDEL failed') end
return 1
LUA, 2, $this->stream, $this->errors, $this->groupName, $id, '', '', '', $this->deleteAfterAck ? '1' : '0'), fn ($result) => $result === 0 || $result === 1);
    }

    private function deadLetter(array $delivery, string $error): void
    {
        RedisResult::run("Stream dead-letter {$delivery['id']}", fn () => Redis::connection('nosql')->eval(<<<'LUA'
local pending = redis.call('XPENDING', KEYS[1], ARGV[1], ARGV[2], ARGV[2], 1)
if #pending == 0 then return 0 end
-- Validate cleanup before writing: Lua errors do not roll back earlier writes.
local errorsType = redis.call('TYPE', KEYS[3]).ok
if errorsType ~= 'none' and errorsType ~= 'hash' then return redis.error_reply('WRONGTYPE retry errors key') end
local written = redis.call('XADD', KEYS[2], '*', 'original_id', ARGV[2], 'event', ARGV[3], 'attempts', ARGV[4], 'last_error', ARGV[5])
if not written then return redis.error_reply('DLQ XADD failed') end
redis.call('HDEL', KEYS[3], ARGV[2])
if redis.call('XACK', KEYS[1], ARGV[1], ARGV[2]) ~= 1 then return redis.error_reply('DLQ XACK failed') end
-- A missing payload is legitimate when the PEL outlives the stream entry.
local deleted = 0
if ARGV[6] == '1' then deleted = redis.call('XDEL', KEYS[1], ARGV[2]) end
if deleted ~= 0 and deleted ~= 1 then return redis.error_reply('DLQ XDEL failed') end
return 1
LUA, 3, $this->stream, $this->dlq, $this->errors, $this->groupName, $delivery['id'], $delivery['event'], $delivery['attempts'], $error, $this->deleteAfterAck ? '1' : '0'),
            fn ($result) => $result === 0 || $result === 1);
    }

    /** Traverse the PEL in exclusive-ID pages so ineligible old leases cannot starve work. */
    private function recoverablePending(): iterable
    {
        $cursor = '-';
        do {
            $page = RedisResult::run('Stream pending page '.$cursor,
                fn () => $this->redis()->xPending($this->stream, $this->groupName, $cursor, '+', $this->pendingPageSize),
                fn ($result) => is_array($result));
            foreach ($page as $row) {
                yield $row;
            }
            if ($page !== []) {
                $cursor = '('.$page[array_key_last($page)][0];
            }
        } while (count($page) === $this->pendingPageSize);
    }

    /** Bounded inspection: oldest 1000 pending entries. */
    public function pending(): array
    {
        $this->group();

        return RedisResult::run('Stream inspect pending', fn () => $this->redis()->xPending($this->stream, $this->groupName, '-', '+', 1000), fn ($r) => is_array($r));
    }

    public function deadLetters(): array
    {
        return RedisResult::run('Stream inspect DLQ', fn () => $this->redis()->xRange($this->dlq, '-', '+', 100), fn ($r) => is_array($r));
    }

    public function lengths(): array
    {
        $this->group();
        $summary = RedisResult::run('Stream inspect summary', fn () => $this->redis()->xPending($this->stream, $this->groupName), fn ($r) => is_array($r));

        return ['stream' => RedisResult::run('Stream XLEN', fn () => $this->redis()->xLen($this->stream), fn ($r) => is_int($r)), 'pending' => $summary[0], 'dead_letters' => RedisResult::run('Stream DLQ XLEN', fn () => $this->redis()->xLen($this->dlq), fn ($r) => is_int($r))];
    }

    public function replay(string $id): string
    {
        // Non-interleaved move, not failure-atomic: a late error can leave both
        // copies. Preserve event_id/version so repeat processing cannot double-score.
        $result = RedisResult::run("Stream replay $id", fn () => Redis::connection('nosql')->eval(<<<'LUA'
local entry = redis.call('XRANGE', KEYS[1], ARGV[1], ARGV[1])
if #entry == 0 then return 0 end
local fields = entry[1][2]
local event
for i = 1, #fields, 2 do if fields[i] == 'event' then event = fields[i+1] end end
if not event then return redis.error_reply('Dead-letter payload missing') end
local id = redis.call('XADD', KEYS[2], '*', 'event', event)
if not id then return redis.error_reply('Replay XADD failed') end
if redis.call('XDEL', KEYS[1], ARGV[1]) ~= 1 then return redis.error_reply('Replay XDEL failed') end
return id
LUA, 2, $this->dlq, $this->stream, $id), fn ($result) => $result === 0 || (is_string($result) && preg_match('/^\d+-\d+$/D', $result)));
        if ($result === 0) {
            throw new \InvalidArgumentException('Dead-letter entry not found');
        }

        return $result;
    }
}
