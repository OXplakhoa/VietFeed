<?php

namespace Tests\Concerns;

use App\SourceHistory\SourceFetchHistory;

trait SourceHistoryFixtures
{
    private function bootSourceHistory(): SourceFetchHistory
    {
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.source_history_retry_ms' => 1]);
        $history = app(SourceFetchHistory::class);
        $history->bootstrap();

        return $history;
    }
}
