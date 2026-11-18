<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\SourceHistory\SourceFetchHistory;
use Illuminate\Console\Command;
use Throwable;

class SourceHistory extends Command
{
    protected $signature = 'sources:history {--source= : MongoDB source ID} {--days=1 : Number of UTC calendar days, including today}';

    protected $description = 'Show one RSS source’s fetch outcomes over recent UTC days';

    public function handle(SourceFetchHistory $history): int
    {
        $sourceId = $this->option('source');
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1, 'max_range' => SourceFetchHistory::RETENTION_DAYS,
        ]]);
        if (! is_string($sourceId) || $sourceId === '' || $days === false) {
            $this->error('Provide --source and a --days value from 1 to '.SourceFetchHistory::RETENTION_DAYS.'.');

            return self::INVALID;
        }
        if (Source::find($sourceId) === null) {
            $this->error('Source not found.');

            return self::FAILURE;
        }

        try {
            $rows = $history->forSource($sourceId, days: $days);
        } catch (Throwable $e) {
            $this->error('Source fetch history unavailable ('.$e::class.').');

            return self::FAILURE;
        }

        $this->table(['Attempt', 'Started (UTC)', 'Type', 'Status', 'HTTP', 'Items', 'Duration (ms)', 'Error type'], $rows
            ->map(fn ($row) => [$row->attempt_id, $row->started_at?->utc()->toIso8601String(), $row->type, $row->status,
                $row->http_status, $row->items_found, $row->duration_ms, $row->error_type])->all());

        return self::SUCCESS;
    }
}
