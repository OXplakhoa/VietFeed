<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Rss\FeedIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('feeds:fetch {--source= : Fetch a single source by ID}')]
#[Description('Fetch RSS feeds from active sources and store new articles')]
class FetchFeeds extends Command
{
    public function __construct(private readonly FeedIngestionService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sources = Source::query()
            ->where('is_active', true)
            ->when($this->option('source'), fn ($query, $sourceId) => $query->whereKey($sourceId))
            ->with('category')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('No matching active sources found.');

            return self::SUCCESS;
        }

        $this->info("Fetching {$sources->count()} source(s)...");
        $totalNew = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($sources as $source) {
            try {
                $result = $this->service->fetchSource($source, 'scheduled_fetch');
                $totalNew += $result->itemsCreated;
                $totalUpdated += $result->itemsUpdated;

                $color = $result->status === 'warning' ? 'yellow' : 'green';
                $this->line("  <fg={$color}>✓</> {$source->name}: {$result->itemsCreated} new, {$result->itemsUpdated} updated ({$result->status})");
            } catch (\Throwable $e) {
                $totalFailed++;
                $this->line("  <fg=red>✗</> {$source->name}: {$e->getMessage()}");
                Log::error("feeds:fetch failed for source [{$source->id}] {$source->name}", [
                    'error' => $e->getMessage(),
                    'feed_url' => $source->feed_url,
                ]);
            }
        }

        $this->newLine();
        $this->info("Done. Total: {$totalNew} new, {$totalUpdated} updated, {$totalFailed} failed.");

        return self::SUCCESS;
    }
}
