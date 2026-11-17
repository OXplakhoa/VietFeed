<?php

namespace App\Moderation;

use App\Interaction\RedisResult;
use App\Models\Sanction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Throwable;

/**
 * Redis review queue and counters: a rebuildable projection of MongoDB case state (spec §9.4).
 *
 * Redis runs with allkeys-lru, so either key can disappear at any time. `meta.count` is kept equal
 * to the sorted-set size by the same Lua call that changes the set; a read trusts Redis only when
 * `meta` exists and `count == ZCARD`, otherwise it answers from MongoDB and rebuilds lazily.
 */
final class ModerationQueue
{
    public const KEY = 'moderation:queue:v1';

    public const META = 'moderation:queue:v1:meta';

    public const TMP = 'moderation:queue:v1:tmp';

    public const TMP_META = 'moderation:queue:v1:tmp-meta';

    /** Recently decided cases keep a version tombstone after a rebuild, so a late stale ZADD is ignored. */
    public const TOMBSTONE_HOURS = 24;

    public const LOCK = 'moderation:queue:v1:rebuild-lock';

    public const COUNTERS = 'moderation:counters:v1';

    public const COUNTER_TTL = 300;

    private const AGE_SLOT = 4294967296; // 2^32

    /** Higher is more urgent: distinct pending reporters, then severity, then oldest round first. */
    public static function score(array $case): float
    {
        $started = $case['round_started_at'] ?? null;
        $seconds = $started instanceof UTCDateTime ? intdiv((int) (string) $started, 1000) : 0;

        return (float) (((int) $case['pending_count'] * 4 + (int) $case['severity']) * self::AGE_SLOT + (self::AGE_SLOT - 1 - $seconds));
    }

    /**
     * Mirror one case: member while pending, removed otherwise. No-op until the queue has been built.
     * Version-aware: `meta` keeps `v:<case id>` (also after removal, as a tombstone) and a write carrying
     * an older case version than the one already applied is ignored, so out-of-order syncs from
     * concurrent requests can never resurrect a decided case or undo a newer score.
     */
    public function sync(array $case): void
    {
        $pending = ($case['status'] ?? null) === 'pending' && (int) ($case['pending_count'] ?? 0) > 0;
        try {
            $this->guard();
            RedisResult::run('Moderation queue sync', fn () => Redis::connection('nosql')->eval(<<<'LUA'
if redis.call('EXISTS', KEYS[2]) == 0 then return -1 end
local field = 'v:' .. ARGV[1]
local applied = tonumber(redis.call('HGET', KEYS[2], field) or '-1')
if applied > tonumber(ARGV[3]) then return -2 end
redis.call('HSET', KEYS[2], field, ARGV[3])
local delta
if ARGV[2] == '' then
    delta = -redis.call('ZREM', KEYS[1], ARGV[1])
else
    delta = redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1])
end
redis.call('HINCRBY', KEYS[2], 'count', delta)
return delta
LUA, 2, self::KEY, self::META, (string) $case['_id'], $pending ? sprintf('%.0f', self::score($case)) : '', (int) ($case['version'] ?? 0)), fn ($r) => is_int($r));
        } catch (Throwable $e) {
            $this->warn('sync', $e);
        }
    }

    /** @return array{valid: bool, count: int} */
    public function state(): array
    {
        try {
            $this->guard();
            [$exists, $count, $card] = RedisResult::run('Moderation queue state', fn () => Redis::connection('nosql')->eval(<<<'LUA'
return {redis.call('EXISTS', KEYS[2]), tonumber(redis.call('HGET', KEYS[2], 'count') or '-1'), redis.call('ZCARD', KEYS[1])}
LUA, 2, self::KEY, self::META), fn ($r) => is_array($r) && count($r) === 3);

            return ['valid' => $exists === 1 && $count === $card, 'count' => (int) $card];
        } catch (Throwable $e) {
            $this->warn('state', $e);

            return ['valid' => false, 'count' => 0];
        }
    }

    /** Case ids of one page in priority order, or null when Redis cannot be trusted. */
    public function page(int $page, int $perPage): ?array
    {
        $state = $this->state();
        if (! $state['valid']) {
            return null;
        }
        try {
            $start = ($page - 1) * $perPage;
            $ids = RedisResult::run('Moderation queue page', fn () => Redis::connection('nosql')->zRevRange(self::KEY, $start, $start + $perPage - 1), fn ($r) => is_array($r));

            return ['ids' => array_values($ids), 'total' => $state['count']];
        } catch (Throwable $e) {
            $this->warn('page', $e);

            return null;
        }
    }

    /** Drop a stale member seen on read (the case is decided at $version, or gone: PHP_INT_MAX tombstone). */
    public function remove(string $caseId, int $version = PHP_INT_MAX): void
    {
        $this->sync(['_id' => $caseId, 'status' => 'stale', 'version' => $version]);
    }

    /** Pending cases count: Redis when trustworthy, otherwise MongoDB plus a lazy rebuild. */
    public function pendingCases(): int
    {
        $state = $this->state();
        if ($state['valid']) {
            return $state['count'];
        }
        $this->rebuildQuietly();

        return $this->cases()->countDocuments(['status' => 'pending', 'pending_count' => ['$gt' => 0]]);
    }

    public function pendingAppeals(): int
    {
        try {
            $this->guard();
            $cached = RedisResult::run('Moderation counters HGET', fn () => Redis::connection('nosql')->hGet(self::COUNTERS, 'pending_appeals'), fn ($r) => $r === false || is_string($r));
            if (is_string($cached) && ctype_digit($cached)) {
                return (int) $cached;
            }
        } catch (Throwable $e) {
            $this->warn('counters', $e);
        }

        return $this->refreshCounters();
    }

    /** Recompute from MongoDB (convergent: no increments that could drift after a crash). */
    public function refreshCounters(): int
    {
        $count = Sanction::where('appeal_status', 'pending')->count();
        try {
            $this->guard();
            RedisResult::run('Moderation counters HSET', fn () => Redis::connection('nosql')->eval(<<<'LUA'
redis.call('HSET', KEYS[1], 'pending_appeals', ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1
LUA, 1, self::COUNTERS, $count, self::COUNTER_TTL), fn ($r) => $r === 1);
        } catch (Throwable $e) {
            $this->warn('counters', $e);
        }

        return $count;
    }

    /** Rebuild from MongoDB; returns the number of queued cases. */
    public function rebuild(): int
    {
        $this->guard();
        $redis = Redis::connection('nosql');
        $locked = RedisResult::run('Moderation queue lock', fn () => $redis->client()->set(self::LOCK, '1', ['NX', 'EX' => 30]), fn ($r) => is_bool($r));
        if (! $locked) {
            return -1;
        }
        try {
            // Changes after this instant are re-synced below, so none is lost to the replace.
            $startedAt = new UTCDateTime(now()->subSecond());
            RedisResult::run('Moderation queue tmp DEL', fn () => $redis->client()->del(self::TMP, self::TMP_META), fn ($r) => is_int($r));
            $count = 0;
            $fields = 0;
            $batch = [];
            $versions = [];
            $flush = function () use (&$batch, &$versions, $redis) {
                if ($batch) {
                    $args = $batch;
                    RedisResult::run('Moderation queue tmp ZADD', fn () => $redis->client()->zAdd(self::TMP, ...$args), fn ($r) => is_int($r));
                    $batch = [];
                }
                if ($versions) {
                    $map = $versions;
                    RedisResult::run('Moderation queue tmp HSET', fn () => $redis->client()->hMSet(self::TMP_META, $map), fn ($r) => $r === true);
                    $versions = [];
                }
            };
            $filter = ['$or' => [
                ['status' => 'pending', 'pending_count' => ['$gt' => 0]],
                ['updated_at' => ['$gte' => new UTCDateTime(now()->subHours(self::TOMBSTONE_HOURS))]],
            ]];
            foreach ($this->cases()->find($filter, ['projection' => ['status' => 1, 'pending_count' => 1, 'severity' => 1, 'round_started_at' => 1, 'version' => 1]]) as $case) {
                $versions['v:'.$case['_id']] = (int) ($case['version'] ?? 0);
                $fields++;
                if ($case['status'] === 'pending' && $case['pending_count'] > 0) {
                    array_push($batch, self::score($case), (string) $case['_id']);
                    $count++;
                }
                if (count($versions) >= 500) {
                    $flush();
                }
            }
            $flush();
            if (! $this->swap($count, $fields)) {
                // A temporary key was evicted while building: keep answering from MongoDB.
                throw new \RuntimeException('Moderation queue rebuild aborted: temporary set incomplete');
            }
            foreach ($this->cases()->find(['updated_at' => ['$gte' => $startedAt]]) as $case) {
                $this->sync($case);
            }
            $this->refreshCounters();

            return $count;
        } finally {
            try {
                $redis->client()->del(self::LOCK);
            } catch (Throwable) {
                // The lock expires on its own.
            }
        }
    }

    /**
     * Atomically publish the rebuilt set, but only when nothing was evicted while building it:
     * ZCARD(tmp) must equal the expected member count and the version map must be complete.
     * On mismatch everything is dropped (meta absent ⇒ reads fall back to MongoDB).
     */
    public function swap(int $expected, int $expectedFields): bool
    {
        $this->guard();

        return RedisResult::run('Moderation queue swap', fn () => Redis::connection('nosql')->eval(<<<'LUA'
if redis.call('ZCARD', KEYS[1]) ~= tonumber(ARGV[2]) or redis.call('HLEN', KEYS[4]) ~= tonumber(ARGV[3]) then
    redis.call('DEL', KEYS[1], KEYS[4], KEYS[3])
    return 0
end
if redis.call('EXISTS', KEYS[1]) == 1 then
    redis.call('RENAME', KEYS[1], KEYS[2])
else
    redis.call('DEL', KEYS[2])
end
if redis.call('EXISTS', KEYS[4]) == 1 then
    redis.call('RENAME', KEYS[4], KEYS[3])
else
    redis.call('DEL', KEYS[3])
end
redis.call('HSET', KEYS[3], 'count', redis.call('ZCARD', KEYS[2]), 'built_at', ARGV[1])
return 1
LUA, 4, self::TMP, self::KEY, self::META, self::TMP_META, now()->toIso8601String(), $expected, $expectedFields), fn ($r) => $r === 0 || $r === 1) === 1;
    }

    public function rebuildQuietly(): void
    {
        try {
            $this->rebuild();
        } catch (Throwable $e) {
            $this->warn('rebuild', $e);
        }
    }

    private function cases(): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection('moderation_cases', ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    private function guard(): void
    {
        if (app()->runningUnitTests() && Redis::connection('nosql')->client()->getDbNum() !== 15) {
            throw new \RuntimeException('Refusing moderation queue test outside Redis DB15');
        }
    }

    private function warn(string $operation, Throwable $e): void
    {
        // Never log keys, payloads or driver diagnostics.
        Log::warning('Moderation queue unavailable', ['operation' => $operation, 'exception_type' => $e::class]);
    }
}
