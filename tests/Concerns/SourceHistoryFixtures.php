<?php

namespace Tests\Concerns;

use App\SourceHistory\SourceFetchHistory;
use App\StoryProcessing\CassandraClient;

trait SourceHistoryFixtures
{
    private function bootSourceHistory(): SourceFetchHistory
    {
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.source_history_retry_ms' => 1]);
        $history = app(SourceFetchHistory::class);
        $history->bootstrap();

        return $history;
    }

    private function clearSourceHistoryImportMarker(): void
    {
        $client = app(CassandraClient::class);
        $this->assertSame('vietfeed_test', $client->keyspace());
        $client->execute('DELETE FROM vietfeed_test.'.SourceFetchHistory::IMPORTS." WHERE name = 'legacy-sql'")->asVoidResult();
    }
}
