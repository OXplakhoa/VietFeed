<?php

namespace App\StoryProcessing;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Types\CypherList;

/** Central client construction; do not expose driver errors containing credentials to CLI. */
final class Neo4jClient
{
    private ?ClientInterface $client = null;

    public function run(string $query, array $parameters = []): CypherList
    {
        try {
            if (! $this->client) {
                $cfg = config('nosql.neo4j');
                $this->client = ClientBuilder::create()->withDriver('bolt', sprintf('bolt://%s:%d', $cfg['host'], (int) $cfg['bolt_port']), Authenticate::basic($cfg['username'] ?? 'neo4j', $cfg['password'] ?? ''))->build();
            }

            return $this->client->run($query, $parameters);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Neo4j request failed ('.$e::class.')', 0, $e);
        }
    }
}
