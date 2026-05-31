<?php

namespace App\Jobs;

use App\Models\Source;
use App\Models\SourceFetchLog;
use App\Services\Rss\Exceptions\FeedFetchException;
use App\Services\Rss\FeedIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchSourceFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $sourceId,
        public int $logId,
    ) {}

    public function backoff(): array
    {
        return config('source_health.backoff', [5, 30, 120, 600, 1800]);
    }

    public function handle(FeedIngestionService $service): void
    {
        $source = Source::findOrFail($this->sourceId);
        $log = SourceFetchLog::findOrFail($this->logId);

        $log->forceFill([
            'status' => 'running',
            'started_at' => $log->started_at ?? now(),
        ])->save();

        try {
            $service->fetchSource($source, 'manual_retry', $log);
        } catch (FeedFetchException $e) {
            if ($e->retryable) {
                throw $e;
            }
        }
    }
}
