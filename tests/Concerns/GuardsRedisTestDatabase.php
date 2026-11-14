<?php

namespace Tests\Concerns;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

trait GuardsRedisTestDatabase
{
    protected function isolateRedis(array $names): void
    {
        foreach ($names as $name) {
            config(["database.redis.$name.url" => null, "database.redis.$name.database" => 15]);

        }
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        foreach ($names as $name) {
            $this->assertSame(15, Redis::connection($name)->client()->getDbNum());
        }
    }

    protected function flushTestDatabase(Connection $connection): void
    {
        $this->assertSame(15, $connection->client()->getDbNum(), 'Refusing to flush Redis outside DB15.');
        $connection->flushdb();
    }
}
