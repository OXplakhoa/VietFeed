<?php

namespace App\StoryProcessing;

use App\Models\Article;
use App\Models\Story;

class StoryGraph
{
    public function __construct(private Neo4jClient $client) {}

    public function project(Story $story, int $version): void
    {
        $ids = collect($story->events)->where('aggregate_version', '<=', $version)->pluck('article_id')->unique()->values()->all();
        $evidence = Article::whereIn('_id', $ids)->get();
        if ($evidence->count() !== count($ids)) {
            throw new \RuntimeException('Graph projection missing canonical Articles');
        }
        $articles = $evidence->map(fn (Article $article) => [
            'uuid' => $article->id, 'title' => $article->title,
            'source' => $article->source_snapshot, 'category' => $article->category_snapshot,
        ])->all();
        // One Cypher statement/transaction: no ready marker before all relationships exist.
        $result = $this->client->run(<<<'CYPHER'
MERGE (s:Story {uuid: $id})
WITH s, coalesce(s.version, 0) AS held
CALL {
WITH s, held
WITH s, held WHERE held >= $version
RETURN true AS covered, held AS version, 0 AS projected
UNION
WITH s, held
WITH s, held WHERE held < $version
UNWIND $articles AS item
MERGE (a:Article {uuid: item.uuid}) SET a.title = item.title
MERGE (src:Source {uuid: item.source.uuid}) SET src.name = item.source.name, src.url = item.source.url
MERGE (c:Category {uuid: item.category.uuid}) SET c.name = item.category.name, c.slug = item.category.slug
MERGE (a)-[:PART_OF]->(s)
MERGE (a)-[:FROM]->(src)
MERGE (a)-[:IN]->(c)
WITH s, count(a) AS projected
FOREACH (_ IN CASE WHEN projected = $expected_count THEN [1] ELSE [] END |
SET s.version = $version, s.title = $title)
RETURN false AS covered, coalesce(s.version, 0) AS version, projected
}
RETURN covered, version, projected
CYPHER, ['id' => $story->id, 'version' => $version, 'title' => $story->title, 'articles' => $articles, 'expected_count' => count($ids)]);
        if ($result->count() === 1 && $result->first()->get('covered') === true && $result->first()->get('version') >= $version) {
            return;
        }
        if ($result->count() !== 1 || $result->first()->get('covered') !== false || $result->first()->get('projected') !== count($ids) || $result->first()->get('version') !== $version) {
            throw new \RuntimeException('Graph projection count does not match canonical Article prefix');
        }
    }

    /** Paused rebuild precondition: reset every affected marker before dispatch. */
    public function resetForRebuild(array $storyIds): void
    {
        try {
            $this->client->run('MATCH (s:Story) WHERE s.uuid IN $ids SET s.version = 0 RETURN count(s) AS reset', ['ids' => $storyIds]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot rebuild: graph reset failed; Mongo state and dispatch unchanged. Use ordinary retry/replay while the graph store is down.', 0, $e);
        }
    }

    public function sources(string $storyId): array
    {
        $rows = $this->client->run(<<<'CYPHER'
MATCH (:Story {uuid: $id})<-[:PART_OF]-(a:Article)-[:FROM]->(src:Source)
RETURN src.uuid AS uuid, src.name AS name, src.url AS url, count(DISTINCT a) AS article_count
ORDER BY uuid
CYPHER, ['id' => $storyId]);

        return $rows->map(fn ($row) => $row->toArray())->toArray();
    }
}
