<?php

namespace App\Interaction;

use Illuminate\Support\Facades\Redis;

final class Trending
{
    public const KEY = 'interactions:trending:v1';

    // Scores and idempotency/version metadata share ONE eviction unit. Metadata
    // has non-positive scores and is never returned as a ranked article.
    private const APPLY = <<<'LUA'
local seen = 'event:' .. ARGV[1]
local version = 'version:' .. ARGV[2]
if redis.call('ZSCORE', KEYS[1], seen) then return 0 end
local previous = redis.call('ZSCORE', KEYS[1], version)
if previous and -tonumber(previous) >= tonumber(ARGV[3]) then return 0 end
redis.call('ZADD', KEYS[1], 0, seen, -tonumber(ARGV[3]), version)
redis.call('ZINCRBY', KEYS[1], ARGV[4], 'article:' .. ARGV[5])
return 1
LUA;

    public function apply(InteractionEvent $event): void
    {
        $this->applyTo(self::KEY, $event);
    }

    private function applyTo(string $key, InteractionEvent $event): void
    {
        $e = $event->data;
        RedisResult::run("Trending apply $key event {$e['event_id']}",
            fn () => Redis::connection('nosql')->eval(self::APPLY, 1, $key, $e['event_id'], $e['aggregate_id'], $e['aggregate_version'], $e['type'] === 'boost' ? 5 : 1, $e['article_id']),
            fn ($result) => $result === 0 || $result === 1);
    }

    public function top(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Top limit must be 1..100');
        }
        $ranked = Redis::connection('nosql')->zrevrange(self::KEY, 0, $limit - 1, true);
        $result = [];
        foreach ($ranked as $member => $score) {
            if ($score > 0 && str_starts_with($member, 'article:')) {
                $result[substr($member, 8)] = (float) $score;
            }
        }

        return $result;
    }

    public function rebuild(InteractionReplaySource $source): int
    {
        // Build off to the side; invalid replay leaves the old ranking intact.
        // Operators pause consumers during rebuild; concurrent writes would be lost.
        $redis = Redis::connection('nosql');
        $key = self::KEY.':rebuild:'.bin2hex(random_bytes(8));
        $count = 0;
        try {
            RedisResult::run("Trending rebuild zadd $key", fn () => $redis->zadd($key, 0, 'rebuild:sentinel'), fn ($result) => $result === 1);
            foreach ($source->events() as $event) {
                $this->applyTo($key, $event);
                $count++;
            }
            RedisResult::run("Trending rebuild rename $key", fn () => $redis->rename($key, self::KEY), fn ($result) => $result === true);
        } catch (\Throwable $e) {
            RedisResult::run("Trending rebuild cleanup $key", fn () => $redis->del($key), fn ($result) => $result === 0 || $result === 1);

            throw $e;
        }

        return $count;
    }
}
