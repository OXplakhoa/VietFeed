<?php

namespace Tests\Integration;

use Cassandra\Connection;
use Cassandra\Connection\StreamNodeConfig;
use Cassandra\Consistency;
use Cassandra\Exception\ServerException;
use Cassandra\Value\Uuid;
use Tests\TestCase;

// Gate 2 spike (GitHub #2): pure-PHP CQL via mroosz/php-cassandra against the
// T1 single-node container. Container-backed; NOT in phpunit suites, run with:
//   vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php
// Requires cassandra healthy (`./scripts/nosql-health.sh`).
class CassandraSpikeTest extends TestCase
{
    private const KEYSPACE = 'vietfeed_spike';

    private function connection(string $keyspace = ''): Connection
    {
        $cfg = config('nosql.cassandra');
        $conn = new Connection(
            [new StreamNodeConfig(host: $cfg['host'], port: (int) $cfg['port'])],
            keyspace: $keyspace,
        );
        $conn->connect();
        $conn->setConsistency(Consistency::ONE);

        return $conn;
    }

    public function test_timeline_append_and_partition_read(): void
    {
        $admin = $this->connection();
        $admin->query('CREATE KEYSPACE IF NOT EXISTS '.self::KEYSPACE." WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
        $admin->query('CREATE TABLE IF NOT EXISTS '.self::KEYSPACE.'.story_timeline (story_id uuid, event_time timeuuid, kind text, payload text, PRIMARY KEY (story_id, event_time)) WITH CLUSTERING ORDER BY (event_time ASC)');

        $conn = $this->connection(self::KEYSPACE);
        $storyIdStr = self::fakerUuid();
        $storyId = new Uuid($storyIdStr);
        $payload = 'spike-'.bin2hex(random_bytes(4));
        $conn->query(
            'INSERT INTO story_timeline (story_id, event_time, kind, payload) VALUES (?, now(), ?, ?)',
            [$storyId, 'created', $payload],
        );

        $rows = iterator_to_array($conn->query(
            'SELECT story_id, kind, payload FROM story_timeline WHERE story_id = ?',
            [$storyId],
        )->asRowsResult());

        $this->assertCount(1, $rows);
        $this->assertSame($payload, $rows[0]['payload']);
        $this->assertSame($storyIdStr, $rows[0]['story_id']);
    }

    public function test_invalid_query_surfaces_typed_error(): void
    {
        $this->expectException(ServerException::class);
        $this->connection(self::KEYSPACE)->query('SELECT * FROM no_such_table');
    }

    public function test_disconnect_and_reconnect_rereads_partition(): void
    {
        $conn = $this->connection(self::KEYSPACE);
        $storyId = new Uuid(self::fakerUuid());
        $conn->query(
            'INSERT INTO story_timeline (story_id, event_time, kind, payload) VALUES (?, now(), ?, ?)',
            [$storyId, 'created', 'reconnect-probe'],
        );

        $conn->disconnect();
        $fresh = $this->connection(self::KEYSPACE);
        $rows = iterator_to_array($fresh->query(
            'SELECT payload FROM story_timeline WHERE story_id = ?',
            [$storyId],
        )->asRowsResult());

        $this->assertCount(1, $rows);
        $this->assertSame('reconnect-probe', $rows[0]['payload']);
    }

    private function fakerUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000, mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
        );
    }
}
