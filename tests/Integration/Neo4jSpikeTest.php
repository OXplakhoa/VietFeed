<?php

namespace Tests\Integration;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Exception\Neo4jException;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;

// Gate 4 spike (GitHub #11): laudis/neo4j-php-client ^3.6 (locked 3.6.1) over Bolt
// against the T1 single-node container. Client why (3 lines): neo4j.com
// community-drivers page recommends it as the PHP client over Bolt/HTTP; v3.x
// supports Neo4j ^4.0/^5.0 + PHP ^8.1 (covers repo ^8.3/8.4); graphaware client
// is ARCHIVED, laudis is its maintained, testkit-validated successor.
// Container-backed; NOT in phpunit suites, run with:
//   vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php
// Requires neo4j healthy (`./scripts/nosql-health.sh`).
class Neo4jSpikeTest extends TestCase
{
    private function client(): ClientInterface
    {
        $cfg = config('nosql.neo4j');
        $password = $cfg['password'] ?? null;
        $this->assertNotEmpty(
            $password,
            'Missing NEO4J_PASSWORD for spike (env-only; see .env.example + config/nosql.php).'
        );

        return ClientBuilder::create()
            ->withDriver(
                'bolt',
                sprintf('bolt://%s:%d', $cfg['host'], (int) $cfg['bolt_port']),
                Authenticate::basic($cfg['username'] ?? 'neo4j', $password)
            )
            ->build();
    }

    private function cleanup(ClientInterface $client, array $uuids): void
    {
        $client->run('MATCH (n) WHERE n.uuid IN $uuids DETACH DELETE n', ['uuids' => $uuids]);
    }

    public function test_bolt_connect_and_idempotent_upsert(): void
    {
        $client = $this->client();
        $storyUuid = Uuid::v7()->toRfc4122();
        $articleUuid = Uuid::v7()->toRfc4122();

        $upsert = 'MERGE (s:Story {uuid: $storyUuid}) '
            .'MERGE (a:Article {uuid: $articleUuid}) '
            .'MERGE (a)-[:PART_OF]->(s)';
        $params = ['storyUuid' => $storyUuid, 'articleUuid' => $articleUuid];

        try {
            $client->run($upsert, $params);
            $client->run($upsert, $params); // re-run adds nothing

            $stories = $client->run(
                'MATCH (s:Story) WHERE s.uuid = $uuid RETURN count(s) AS c', ['uuid' => $storyUuid]
            )->first()->get('c');
            $articles = $client->run(
                'MATCH (a:Article) WHERE a.uuid = $uuid RETURN count(a) AS c', ['uuid' => $articleUuid]
            )->first()->get('c');
            $edges = $client->run(
                'MATCH (a:Article {uuid: $articleUuid})-[:PART_OF]->(s:Story {uuid: $storyUuid}) RETURN count(*) AS c',
                $params
            )->first()->get('c');

            $this->assertSame(1, $stories);
            $this->assertSame(1, $articles);
            $this->assertSame(1, $edges);
        } finally {
            $this->cleanup($client, [$storyUuid, $articleUuid]);
        }
    }

    public function test_multi_hop_story_article_source_entity_query(): void
    {
        $client = $this->client();
        $uuids = [
            'story' => Uuid::v7()->toRfc4122(),
            'article' => Uuid::v7()->toRfc4122(),
            'source' => Uuid::v7()->toRfc4122(),
            'entity' => Uuid::v7()->toRfc4122(),
            'aspect' => Uuid::v7()->toRfc4122(),
        ];

        try {
            $client->run(
                'MERGE (s:Story {uuid: $story}) '
                .'MERGE (a:Article {uuid: $article}) '
                .'MERGE (src:Source {uuid: $source}) '
                .'MERGE (e:Entity {uuid: $entity}) '
                .'MERGE (asp:Aspect {uuid: $aspect}) '
                .'MERGE (a)-[:PART_OF]->(s) '
                .'MERGE (a)-[:FROM]->(src) '
                .'MERGE (a)-[:MENTIONS]->(e) '
                .'MERGE (a)-[:EMPHASIZES]->(asp)',
                $uuids
            );

            $row = $client->run(
                'MATCH (s:Story {uuid: $story})<-[:PART_OF]-(a:Article)-[:FROM]->(src:Source), '
                .'(a)-[:MENTIONS]->(e:Entity), '
                .'(a)-[:EMPHASIZES]->(asp:Aspect) '
                .'RETURN s.uuid AS story, a.uuid AS article, src.uuid AS source, '
                .'e.uuid AS entity, asp.uuid AS aspect',
                $uuids
            )->first();

            $this->assertSame($uuids['story'], $row->get('story'));
            $this->assertSame($uuids['article'], $row->get('article'));
            $this->assertSame($uuids['source'], $row->get('source'));
            $this->assertSame($uuids['entity'], $row->get('entity'));
            $this->assertSame($uuids['aspect'], $row->get('aspect'));

            $rels = $client->run(
                'MATCH (s:Story {uuid: $story})<-[r1:PART_OF]-(a:Article)-[r2:FROM]->(src:Source), '
                .'(a)-[r3:MENTIONS]->(:Entity), '
                .'(a)-[r4:EMPHASIZES]->(:Aspect) '
                .'RETURN type(r1) AS t1, type(r2) AS t2, type(r3) AS t3, type(r4) AS t4',
                $uuids
            )->first();

            $this->assertSame('PART_OF', $rels->get('t1'));
            $this->assertSame('FROM', $rels->get('t2'));
            $this->assertSame('MENTIONS', $rels->get('t3'));
            $this->assertSame('EMPHASIZES', $rels->get('t4'));
        } finally {
            $this->cleanup($client, array_values($uuids));
        }
    }

    public function test_invalid_cypher_surfaces_typed_error(): void
    {
        $this->expectException(Neo4jException::class);
        $this->client()->run('THIS IS NOT CYPHER');
    }

    public function test_disconnect_and_reconnect_rereads(): void
    {
        $client = $this->client();
        $uuid = Uuid::v7()->toRfc4122();
        $client->run('MERGE (a:Article {uuid: $uuid})', ['uuid' => $uuid]);
        unset($client);

        // Fresh client = fresh Bolt pool session; re-reads what the dropped one wrote.
        $fresh = $this->client();

        try {
            $count = $fresh->run(
                'MATCH (a:Article) WHERE a.uuid = $uuid RETURN count(a) AS c', ['uuid' => $uuid]
            )->first()->get('c');

            $this->assertSame(1, $count);
        } finally {
            $this->cleanup($fresh, [$uuid]);
        }
    }
}
