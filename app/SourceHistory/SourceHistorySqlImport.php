<?php

namespace App\SourceHistory;

use App\Models\Source;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Timestamp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class SourceHistorySqlImport
{
    public function __construct(private CassandraClient $client, private SourceFetchHistory $history) {}

    public function run(bool $force): array
    {
        if (DB::connection()->getDriverName() === 'mongodb' || ! Schema::hasTable('source_fetch_logs')) {
            throw new LogicException('Point the default connection at the legacy SQL database containing source_fetch_logs.');
        }

        $keyspace = $this->client->keyspace();
        $marker = $this->client->rows("INSERT INTO $keyspace.".SourceFetchHistory::IMPORTS
            ." (name, started_at) VALUES ('legacy-sql', ?) IF NOT EXISTS", [new Timestamp(now())]);
        if (! $marker[0]['[applied]'] && ! $force) {
            throw new LogicException('Legacy source history import already started. Use --force to resume an intentional cutover import.');
        }

        $sources = Source::all(['_id', 'feed_url']);
        $byId = $sources->keyBy('id');
        $byFeed = $sources->keyBy('feed_url');
        $legacyFeeds = Schema::hasTable('sources') ? DB::table('sources')->pluck('feed_url', 'id') : collect();
        $counts = ['scanned' => 0, 'written' => 0, 'expired' => 0, 'unresolved_source' => 0, 'missing_timestamp' => 0];

        foreach (DB::table('source_fetch_logs')->lazyById(500) as $row) {
            $counts['scanned']++;
            $at = $row->started_at ?? $row->created_at ?? $row->finished_at ?? $row->updated_at;
            if ($at === null) {
                $counts['missing_timestamp']++;

                continue;
            }
            $time = Carbon::parse($at)->utc();
            if ($time->getTimestamp() <= now()->getTimestamp() - SourceFetchHistory::RETENTION_SECONDS) {
                $counts['expired']++;

                continue;
            }
            $source = $byId->get((string) $row->source_id) ?? $byFeed->get($legacyFeeds->get($row->source_id, ''));
            if ($source === null) {
                $counts['unresolved_source']++;

                continue;
            }
            $written = $this->history->apply([
                'attempt_id' => self::legacyId((string) $row->id, $time),
                'source_id' => $source->id,
                'started_at' => SourceFetchAttempt::timestamp($time),
                'fetch_started_at' => $row->started_at,
                'revision' => 0,
            ] + array_filter((array) $row, fn ($value) => $value !== null));
            $counts[$written ? 'written' : 'expired']++;
        }

        return $counts;
    }

    private static function legacyId(string $id, Carbon $time): string
    {
        // RFC 9562 UUIDv7 layout. The hash makes the 74 free bits reproducible for this legacy row.
        $timestamp = str_pad(dechex($time->getTimestampMs()), 12, '0', STR_PAD_LEFT);
        $hash = hash('sha256', 'vietfeed:source-fetch-history:sql:'.$id);
        $variant = dechex(8 | (hexdec($hash[3]) & 3));

        return substr($timestamp, 0, 8).'-'.substr($timestamp, 8, 4).'-7'.substr($hash, 0, 3)
            .'-'.$variant.substr($hash, 4, 3).'-'.substr($hash, 7, 12);
    }
}
