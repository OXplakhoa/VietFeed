<?php

namespace App\Interaction;

use App\Models\Boost;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Trending
{
    public const KEY = 'interactions:trending:v1';

    public const DEMO_KEY = 'interactions:trending:demo:v1';

    private const READY = 'meta:ready';

    private const LOCK = 'interactions:trending:v1:lock';

    // The live lock spans the Mongo read and this update. Verify its owner at
    // write time too, since an expired or evicted lock can be acquired again.
    private const APPLY = <<<'LUA'
if ARGV[7] ~= '' and redis.call('GET', KEYS[2]) ~= ARGV[7] then
    return redis.error_reply('Trending lock lost')
end
if ARGV[8] ~= '' and not redis.call('ZSCORE', KEYS[1], ARGV[8]) then
    return redis.error_reply('Trending rebuild staging key lost')
end
local membership = ARGV[6] == '1'
local seen = 'event:' .. ARGV[1]
local version = 'version:' .. ARGV[2]
local article = 'article:' .. ARGV[5]
local weight = tonumber(ARGV[4])
if not membership and redis.call('ZSCORE', KEYS[1], seen) then return 0 end
local previous = redis.call('ZSCORE', KEYS[1], version)
if membership and ARGV[3] == '2' then
    if not previous then return 0 end
    if tonumber(previous) == -1 then
        local score = redis.call('ZINCRBY', KEYS[1], -weight, article)
        if tonumber(score) <= 0 then redis.call('ZREM', KEYS[1], article) end
    end
    redis.call('ZREM', KEYS[1], version)
    return 1
end
if previous and -tonumber(previous) >= tonumber(ARGV[3]) then return 0 end
redis.call('ZADD', KEYS[1], -tonumber(ARGV[3]), version)
if not membership then redis.call('ZADD', KEYS[1], 0, seen) end
redis.call('ZINCRBY', KEYS[1], weight, article)
return 1
LUA;

    public function apply(InteractionEvent $event): void
    {
        $e = $event->data;
        if (in_array($e['type'], ['BOOKMARKED', 'UNBOOKMARKED'], true)) {
            return;
        }
        if (! $event->isMembership()) {
            $this->applyTo(self::DEMO_KEY, $event);

            return;
        }

        $this->withLiveLock(function (string $owner) use ($event, $e): void {
            // A late add is treated as a removal when its incarnation is gone.
            // The lock prevents a worker from using a Mongo read made before
            // another worker applied that incarnation's removal.
            $removed = $e['type'] === 'BOOSTED' && ! Boost::whereKey($e['aggregate_id'])
                ->where('article_id', $e['article_id'])->where('user_id', $e['user_id'])->exists();
            $this->applyTo(self::KEY, $event, $removed, $owner);
        });
    }

    /** Remove one boost's contribution (account purge). Idempotent: an unknown or already removed boost is a no-op. */
    public function retract(string $boostId, string $articleId): void
    {
        $this->withLiveLock(fn (string $owner) => RedisResult::run("Trending retract $boostId",
            fn () => Redis::connection('nosql')->eval(self::APPLY, 2, self::KEY, self::LOCK, '', $boostId, 2, 5, $articleId, '1', $owner, ''),
            fn ($result) => $result === 0 || $result === 1));
    }

    private function applyTo(string $key, InteractionEvent $event, bool $removed = false, string $owner = '', bool $staging = false): void
    {
        $e = $event->data;
        $version = $removed ? 2 : $e['aggregate_version'];
        $weight = $e['type'] === 'view' ? 1 : 5;
        RedisResult::run("Trending apply $key event {$e['event_id']}",
            fn () => Redis::connection('nosql')->eval(self::APPLY, 2, $key, self::LOCK, $e['event_id'], $e['aggregate_id'],
                $version, $weight, $e['article_id'], $event->isMembership() ? '1' : '0', $owner, $staging ? self::READY : ''),
            fn ($result) => $result === 0 || $result === 1);
    }

    public function top(int $limit = 10, bool $synthetic = false): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Top limit must be 1..100');
        }
        $key = $synthetic ? self::DEMO_KEY : self::KEY;
        $ranked = RedisResult::run('Trending top',
            fn () => Redis::connection('nosql')->zrevrange($key, 0, $limit - 1, true),
            is_array(...));

        return $this->scores($ranked);
    }

    public function forHome(): array
    {
        try {
            $ranked = RedisResult::run('Trending home', fn () => Redis::connection('nosql')->eval(<<<'LUA'
if not redis.call('ZSCORE', KEYS[1], ARGV[1]) then return {} end
return redis.call('ZREVRANGE', KEYS[1], 0, 99, 'WITHSCORES')
LUA, 1, self::KEY, self::READY), is_array(...));
            if ($ranked !== []) {
                $scores = [];
                for ($i = 0; $i < count($ranked); $i += 2) {
                    $scores[$ranked[$i]] = $ranked[$i + 1];
                }

                return $this->scores($scores);
            }
            $this->rebuild(app(MongoInteractionReplaySource::class));
        } catch (Throwable) {
            // The current request has Mongo counts even if Redis is unavailable or busy.
        }

        return [];
    }

    private function scores(array $ranked): array
    {
        $result = [];
        foreach ($ranked as $member => $score) {
            if ($score > 0 && str_starts_with($member, 'article:')) {
                $result[substr($member, 8)] = (float) $score;
            }
        }

        return $result;
    }

    public function rebuild(InteractionReplaySource $source, bool $synthetic = false): int
    {
        if ($synthetic) {
            return $this->build($source, self::DEMO_KEY, '');
        }

        return $this->withLiveLock(fn (string $owner) => $this->build($source, self::KEY, $owner));
    }

    private function build(InteractionReplaySource $source, string $target, string $owner): int
    {
        $redis = Redis::connection('nosql');
        $key = $target.':rebuild:'.bin2hex(random_bytes(8));
        $count = 0;
        try {
            RedisResult::run("Trending rebuild zadd $key", fn () => $redis->zadd($key, 0, self::READY), fn ($result) => $result === 1);
            foreach ($source->events() as $event) {
                $this->applyTo($key, $event, owner: $owner, staging: true);
                $count++;
            }
            RedisResult::run("Trending rebuild rename $key", fn () => $redis->eval(<<<'LUA'
if ARGV[1] ~= '' and redis.call('GET', KEYS[3]) ~= ARGV[1] then
    return redis.error_reply('Trending lock lost')
end
if not redis.call('ZSCORE', KEYS[1], ARGV[2]) then
    return redis.error_reply('Trending rebuild staging key lost')
end
redis.call('RENAME', KEYS[1], KEYS[2])
return 1
LUA, 3, $key, $target, self::LOCK, $owner, self::READY), fn ($result) => $result === 1);
        } catch (Throwable $e) {
            RedisResult::run("Trending rebuild cleanup $key", fn () => $redis->del($key), fn ($result) => $result === 0 || $result === 1);

            throw $e;
        }

        return $count;
    }

    private function withLiveLock(callable $callback): mixed
    {
        $redis = Redis::connection('nosql');
        $owner = (string) Str::uuid7();
        $locked = RedisResult::run('Trending lock', fn () => $redis->client()->set(self::LOCK, $owner, ['NX', 'EX' => 30]), is_bool(...));
        if (! $locked) {
            throw new RuntimeException('Trending projection busy');
        }

        try {
            return $callback($owner);
        } finally {
            RedisResult::run('Trending unlock', fn () => $redis->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
return redis.call('DEL', KEYS[1])
LUA, 1, self::LOCK, $owner), fn ($result) => $result === 0 || $result === 1);
        }
    }
}
