<?php

namespace App\ReadingPass;

use App\Interaction\RedisResult;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Reading Pass quota windows in Redis: a rebuildable projection of the MongoDB unlock ledger (spec §9.4).
 *
 * One sorted set per subject holds `g:<article>` (granted) and `p:<article>` (a reservation a rebuild found still
 * pending) scored by unlock time in ms, plus the sentinel `~ready:<generation>` at score -1. A set without the
 * sentinel of the current generation (or with the generation key itself missing) is never trusted: DECIDE answers -1
 * and the caller rebuilds from MongoDB.
 * Every method throws on a Redis failure; ReadingPassService owns the fallback.
 */
final class ReadingPassQuota
{
    public const PREFIX = 'reading-pass:v1:';

    public const GEN = 'reading-pass:v1:gen';

    /** Longest window is one Vietnam day (< 25 h back); rolling windows are 5 h. */
    public const RETAIN_MS = 25 * 3600 * 1000;

    public const TTL = 26 * 3600;

    /** A reservation older than this is resolved from MongoDB instead of being counted blindly. */
    public const STALE_MS = 60_000;

    public const LOCK_TTL = 10;

    public const NOT_BUILT = -1;

    public const STALE = -2;

    public const DENIED = 0;

    public const GRANTED = 1;

    public const ALREADY = 2;

    private const DECIDE = <<<'LUA'
local gen = redis.call('GET', KEYS[2])
if not gen or not redis.call('ZSCORE', KEYS[1], '~ready:' .. gen) then return {-1, 0, -1} end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '0', ARGV[5])
local function usage()
    local first = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[3], '+inf', 'WITHSCORES', 'LIMIT', 0, 1)
    local oldest = -1
    if #first == 2 then oldest = tonumber(first[2]) end
    return redis.call('ZCOUNT', KEYS[1], ARGV[3], '+inf'), oldest
end
-- The caller's own reservation is being decided now: never count it against itself.
redis.call('ZREM', KEYS[1], 'p:' .. ARGV[1])
if redis.call('ZSCORE', KEYS[1], 'g:' .. ARGV[1]) then
    local used, oldest = usage()
    return {2, used, oldest}
end
local stale = {-2, 0, -1}
for _, member in ipairs(redis.call('ZRANGEBYSCORE', KEYS[1], '0', ARGV[6])) do
    if string.sub(member, 1, 2) == 'p:' then stale[#stale + 1] = string.sub(member, 3) end
end
if #stale > 3 then return stale end
local used, oldest = usage()
local limit = tonumber(ARGV[4])
if limit >= 0 and used >= limit then return {0, used, oldest} end
redis.call('ZADD', KEYS[1], ARGV[2], 'g:' .. ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[7])
local now = tonumber(ARGV[2])
if oldest < 0 or now < oldest then oldest = now end
return {1, used + 1, oldest}
LUA;

    private const STATE = <<<'LUA'
local gen = redis.call('GET', KEYS[2])
if not gen or not redis.call('ZSCORE', KEYS[1], '~ready:' .. gen) then return {-1, 0, -1} end
local first = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[1], '+inf', 'WITHSCORES', 'LIMIT', 0, 1)
local oldest = -1
if #first == 2 then oldest = tonumber(first[2]) end
return {0, redis.call('ZCOUNT', KEYS[1], ARGV[1], '+inf'), oldest}
LUA;

    private const LOAD = <<<'LUA'
if redis.call('GET', KEYS[3]) ~= ARGV[1] then return 0 end
local gen = redis.call('GET', KEYS[2])
if not gen or gen ~= ARGV[2] then return 0 end
redis.call('DEL', KEYS[1])
redis.call('ZADD', KEYS[1], '-1', '~ready:' .. ARGV[2])
for i = 4, #ARGV, 2 do redis.call('ZADD', KEYS[1], ARGV[i], ARGV[i + 1]) end
redis.call('EXPIRE', KEYS[1], ARGV[3])
return 1
LUA;

    private const RESOLVE = <<<'LUA'
local gen = redis.call('GET', KEYS[2])
if not gen or not redis.call('ZSCORE', KEYS[1], '~ready:' .. gen) then return 0 end
for i = 1, #ARGV, 2 do
    if redis.call('ZREM', KEYS[1], 'p:' .. ARGV[i]) == 1 and ARGV[i + 1] ~= '' then
        redis.call('ZADD', KEYS[1], ARGV[i + 1], 'g:' .. ARGV[i])
    end
end
return 1
LUA;

    private const RELEASE = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end
return 0
LUA;

    public static function windowKey(string $subject): string
    {
        return self::PREFIX.$subject.':w';
    }

    public static function lockKey(string $subject): string
    {
        return self::PREFIX.$subject.':lock';
    }

    /**
     * Atomic decide-and-consume.
     *
     * @return array{status: int, used: int, oldest: ?int, stale: list<string>}
     */
    public function decide(string $subject, string $articleId, int $nowMs, int $windowStartMs, int $limit): array
    {
        $result = $this->eval('Reading Pass decide', self::DECIDE, [self::windowKey($subject), self::GEN], [
            $articleId, $nowMs, $windowStartMs, $limit, $nowMs - self::RETAIN_MS, $nowMs - self::STALE_MS, self::TTL,
        ], fn ($r) => is_array($r) && count($r) >= 3 && in_array($r[0], [-2, -1, 0, 1, 2], true));

        return [
            'status' => $result[0],
            'used' => (int) $result[1],
            'oldest' => $result[2] < 0 ? null : (int) $result[2],
            'stale' => array_map('strval', array_slice($result, 3)),
        ];
    }

    /** @return array{used: int, oldest: ?int}|null null when the window must be rebuilt */
    public function state(string $subject, int $windowStartMs): ?array
    {
        $result = $this->eval('Reading Pass state', self::STATE, [self::windowKey($subject), self::GEN], [$windowStartMs],
            fn ($r) => is_array($r) && count($r) === 3);

        return $result[0] === -1 ? null : ['used' => (int) $result[1], 'oldest' => $result[2] < 0 ? null : (int) $result[2]];
    }

    /**
     * Current generation, created with SET NX when missing. A missing key is never read as a valid generation:
     * every window built under an evicted generation becomes untrusted and rebuilds.
     */
    public function generation(): string
    {
        $client = $this->client();
        RedisResult::run('Reading Pass SET NX gen', fn () => $client->set(self::GEN, (string) Str::uuid7(), ['NX']), is_bool(...));
        $gen = RedisResult::run('Reading Pass GET gen', fn () => $client->get(self::GEN), fn ($r) => $r === false || is_string($r));
        if (! is_string($gen) || $gen === '') {
            throw new \RuntimeException('Reading Pass generation unavailable');
        }

        return $gen;
    }

    /**
     * Publish a rebuilt window. Refused (false) when the caller no longer owns the lock or the generation moved.
     *
     * @param  list<array{0: int, 1: string}>  $members  [score ms, member]
     */
    public function load(string $subject, string $owner, string $generation, array $members): bool
    {
        $args = [$owner, $generation, self::TTL];
        foreach ($members as [$score, $member]) {
            array_push($args, $score, $member);
        }

        return $this->eval('Reading Pass load', self::LOAD, [self::windowKey($subject), self::GEN, self::lockKey($subject)], $args,
            fn ($r) => $r === 0 || $r === 1) === 1;
    }

    /** @param  array<string, ?int>  $articles  article => granted score (convert) or null (remove) */
    public function resolve(string $subject, array $articles): void
    {
        $args = [];
        foreach ($articles as $article => $score) {
            array_push($args, (string) $article, $score === null ? '' : (string) $score);
        }
        $this->eval('Reading Pass resolve', self::RESOLVE, [self::windowKey($subject), self::GEN], $args, fn ($r) => $r === 0 || $r === 1);
    }

    /** Owner token, or null when another rebuild/transfer still holds the subject after waiting. */
    public function lock(string $subject, int $waitMs = 1000): ?string
    {
        $owner = (string) Str::uuid7();
        $deadline = hrtime(true) + $waitMs * 1_000_000;
        do {
            $locked = RedisResult::run('Reading Pass lock', fn () => $this->client()->set(self::lockKey($subject), $owner, ['NX', 'EX' => self::LOCK_TTL]), is_bool(...));
            if ($locked) {
                return $owner;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);

        return null;
    }

    public function unlock(string $subject, string $owner): void
    {
        $this->eval('Reading Pass unlock', self::RELEASE, [self::lockKey($subject)], [$owner], fn ($r) => $r === 0 || $r === 1);
    }

    /** Drop windows: the next decision for each subject rebuilds from MongoDB. */
    public function forget(string ...$subjects): void
    {
        $keys = array_map(self::windowKey(...), $subjects);
        RedisResult::run('Reading Pass DEL', fn () => $this->client()->del($keys), fn ($r) => is_int($r));
    }

    /** Invalidate every window at once (after an import or by an operator); each rebuilds lazily. */
    public function invalidateAll(): string
    {
        $gen = (string) Str::uuid7();
        RedisResult::run('Reading Pass SET gen', fn () => $this->client()->set(self::GEN, $gen), fn ($r) => $r === true);

        return $gen;
    }

    private function eval(string $context, string $script, array $keys, array $args, callable $valid): mixed
    {
        $this->guard();

        return RedisResult::run($context, fn () => Redis::connection('nosql')->eval($script, count($keys), ...$keys, ...$args), $valid);
    }

    private function client(): \Redis
    {
        $this->guard();

        return Redis::connection('nosql')->client();
    }

    private function guard(): void
    {
        if (app()->runningUnitTests() && Redis::connection('nosql')->client()->getDbNum() !== 15) {
            throw new \RuntimeException('Refusing Reading Pass quota test outside Redis DB15');
        }
    }
}
