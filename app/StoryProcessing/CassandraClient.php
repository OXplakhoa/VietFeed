<?php

namespace App\StoryProcessing;

use Cassandra\Connection;
use Cassandra\Connection\StreamNodeConfig;
use Cassandra\Consistency;
use Cassandra\Response\Result;

/** One construction point for the pure PHP CQL client; schema creation is explicit. */
final class CassandraClient
{
    private ?Connection $connection = null;

    public function keyspace(): string
    {
        $keyspace = (string) config('nosql.cassandra.keyspace');
        if (! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $keyspace)) {
            throw new \InvalidArgumentException('Invalid Cassandra keyspace');
        }
        if (app()->environment('testing') && $keyspace !== 'vietfeed_test') {
            throw new \RuntimeException('Refusing Cassandra access outside vietfeed_test while testing');
        }

        return $keyspace;
    }

    public function execute(string $query, array $values = []): Result
    {
        $this->keyspace();
        try {
            if (! $this->connection) {
                $cfg = config('nosql.cassandra');
                $connection = new Connection([new StreamNodeConfig(host: $cfg['host'], port: (int) $cfg['port'])]);
                $connection->connect();
                $connection->setConsistency(Consistency::ONE);
                $this->connection = $connection;
            }

            return $this->connection->query($query, $values);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cassandra request failed ('.$e::class.')', 0, $e);
        }
    }

    public function rows(string $query, array $values = []): array
    {
        return iterator_to_array($this->execute($query, $values)->asRowsResult());
    }
}
