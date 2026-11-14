<?php

namespace App\Interaction;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/** phpredis can return false rather than throw for Redis command/Lua errors. */
final class RedisResult
{
    public static function run(string $context, callable $command, callable $valid): mixed
    {
        $client = Redis::connection('nosql')->client();
        $client->clearLastError();
        try {
            $result = $command();
            $error = $client->getLastError();
            if ($error !== null || ! $valid($result)) {
                throw new RuntimeException($error ?? 'Unexpected Redis result: '.var_export($result, true));
            }

            return $result;
        } catch (Throwable $e) {
            throw new RuntimeException($context.': '.$e->getMessage(), 0, $e);
        } finally {
            $client->clearLastError();
        }
    }
}
