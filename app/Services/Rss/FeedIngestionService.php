<?php

namespace App\Services\Rss;

use App\Models\Article;
use App\Models\Source;
use App\Models\SourceFetchLog;
use App\Services\Rss\Exceptions\FeedFetchException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FeedIngestionService
{
    public function fetchSource(Source $source, string $runType = 'scheduled_fetch', ?SourceFetchLog $log = null): FeedRunResult
    {
        $startedAt = now();
        $log ??= $source->fetchLogs()->create([
            'type' => $runType,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        if (! $log->started_at) {
            $log->forceFill(['started_at' => $startedAt])->save();
        }

        try {
            [$response, $items] = $this->requestFeedItems($source);
            $metrics = $this->buildMetrics($items, $source, false);
            $durationMs = $this->durationMs($startedAt);

            if ($metrics['valid_items'] === 0) {
                throw new FeedFetchException('Feed has no valid items to import.', 'no_valid_items');
            }

            $status = $this->determineOutcomeStatus($metrics, $durationMs);

            $result = new FeedRunResult(
                status: $status,
                httpStatus: $response->status(),
                itemsFound: $metrics['items_found'],
                itemsCreated: $metrics['items_created'],
                itemsUpdated: $metrics['items_updated'],
                validItems: $metrics['valid_items'],
                invalidItems: $metrics['invalid_items'],
                missingImageCount: $metrics['missing_image_count'],
                dateParseErrorCount: $metrics['date_parse_error_count'],
                duplicateCount: $metrics['duplicate_count'],
                durationMs: $durationMs,
                sampleItems: $metrics['sample_items'],
            );

            $this->syncSuccessfulRun($source, $log, $result, $runType);

            return $result;
        } catch (FeedFetchException $e) {
            $this->syncFailedRun($source, $log, $startedAt, $e);
            throw $e;
        } catch (\Throwable $e) {
            $wrapped = new FeedFetchException($e->getMessage(), 'unexpected_error');
            $this->syncFailedRun($source, $log, $startedAt, $wrapped);
            throw $wrapped;
        }
    }

    public function testSource(Source $source, ?SourceFetchLog $log = null): FeedTestResult
    {
        $startedAt = now();
        $log ??= $source->fetchLogs()->create([
            'type' => 'rss_test',
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            [$response, $items] = $this->requestFeedItems($source);
            $metrics = $this->buildMetrics($items, $source, true);
            $durationMs = $this->durationMs($startedAt);
            $status = $metrics['valid_items'] > 0 ? $this->determineOutcomeStatus($metrics, $durationMs) : 'failed';

            $result = new FeedTestResult(
                status: $status,
                httpStatus: $response->status(),
                itemsFound: $metrics['items_found'],
                validItems: $metrics['valid_items'],
                invalidItems: $metrics['invalid_items'],
                missingImageCount: $metrics['missing_image_count'],
                dateParseErrorCount: $metrics['date_parse_error_count'],
                durationMs: $durationMs,
                sampleItems: $metrics['sample_items'],
            );

            $log->forceFill([
                'status' => $result->status,
                'http_status' => $result->httpStatus,
                'items_found' => $result->itemsFound,
                'valid_items' => $result->validItems,
                'invalid_items' => $result->invalidItems,
                'missing_image_count' => $result->missingImageCount,
                'date_parse_error_count' => $result->dateParseErrorCount,
                'duration_ms' => $result->durationMs,
                'finished_at' => now(),
            ])->save();

            return $result;
        } catch (FeedFetchException $e) {
            $durationMs = $this->durationMs($startedAt);
            $log->forceFill([
                'status' => 'failed',
                'error_type' => $e->errorType,
                'error_message' => Str::limit($e->getMessage(), 1000),
                'http_status' => $e->httpStatus,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ])->save();

            return new FeedTestResult(
                status: 'failed',
                httpStatus: $e->httpStatus,
                itemsFound: 0,
                validItems: 0,
                invalidItems: 0,
                missingImageCount: 0,
                dateParseErrorCount: 0,
                durationMs: $durationMs,
                errorType: $e->errorType,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function requestFeedItems(Source $source): array
    {
        if (blank($source->feed_url)) {
            throw new FeedFetchException('Source has no feed URL configured.', 'missing_feed_url');
        }

        $request = Http::accept('application/rss+xml, application/xml, text/xml')
            ->withUserAgent('Mozilla/5.0 (compatible; VietFeedBot/1.0; +https://vietfeed.test)')
            ->timeout(15);

        try {
            $response = $request->get($source->feed_url);

            // Some Vietnamese news sites, e.g. Lao Động, return a small HTML page that sets
            // a Cloudrity cookie on the first request, then serves the XML feed on reload.
            // Laravel's HTTP client does not execute that JavaScript, so we extract the
            // cookie and retry once.
            if ($cookie = $this->extractJavascriptCookie($response->body())) {
                $response = $request->withHeader('Cookie', $cookie)->get($source->feed_url);
            }
        } catch (ConnectionException $e) {
            throw new FeedFetchException('Connection to feed failed or timed out.', 'connection_error', true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $retryable = in_array($status, [429, 500, 502, 503, 504], true);
            throw new FeedFetchException("HTTP {$status}", 'http_error', $retryable, $status);
        }

        $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);

        if ($xml === false) {
            throw new FeedFetchException('HTTP 200 but XML could not be parsed.', 'invalid_xml', false, $response->status());
        }

        $items = $xml->channel->item ?? [];
        $itemsArray = is_iterable($items) ? iterator_to_array($items, false) : [];

        if (count($itemsArray) === 0) {
            throw new FeedFetchException('Feed returned zero items.', 'empty_feed', false, $response->status());
        }

        return [$response, $itemsArray];
    }

    private function extractJavascriptCookie(string $body): ?string
    {
        if (! str_contains($body, 'document.cookie')) {
            return null;
        }

        if (preg_match('/document\.cookie\s*=\s*"([^"]+)"/', $body, $matches)) {
            return explode(';', $matches[1], 2)[0];
        }

        return null;
    }

    private function buildMetrics(array $items, Source $source, bool $dryRun): array
    {
        $metrics = [
            'items_found' => count($items),
            'items_created' => 0,
            'items_updated' => 0,
            'valid_items' => 0,
            'invalid_items' => 0,
            'missing_image_count' => 0,
            'date_parse_error_count' => 0,
            'duplicate_count' => 0,
            'sample_items' => [],
        ];

        foreach ($items as $item) {
            $url = trim((string) $item->link);
            $title = $this->decode((string) $item->title);

            if (blank($url) || blank($title)) {
                $metrics['invalid_items']++;

                continue;
            }

            $description = $this->extractDescription($item);
            $imageUrl = $this->extractImage($item);
            $publishedAt = $this->parseDate((string) $item->pubDate);

            if ($imageUrl === null) {
                $metrics['missing_image_count']++;
            }

            if (filled((string) $item->pubDate) && $publishedAt === null) {
                $metrics['date_parse_error_count']++;
            }

            $metrics['valid_items']++;

            if (count($metrics['sample_items']) < 3) {
                $metrics['sample_items'][] = [
                    'title' => $title,
                    'link' => $url,
                ];
            }

            if ($dryRun) {
                continue;
            }

            $existing = Article::where('original_url', $url)->first();
            $article = Article::updateOrCreate(
                ['original_url' => $url],
                [
                    'source_id' => $source->id,
                    'category_id' => $source->category_id,
                    'title' => $title,
                    'slug' => $this->uniqueSlug($title, $url),
                    'description' => $description,
                    'image_url' => $imageUrl,
                    'published_at' => $publishedAt,
                ]
            );

            if ($existing || ! $article->wasRecentlyCreated) {
                $metrics['items_updated']++;
                $metrics['duplicate_count']++;
            } else {
                $metrics['items_created']++;
            }
        }

        return $metrics;
    }

    private function syncSuccessfulRun(Source $source, SourceFetchLog $log, FeedRunResult $result, string $runType): void
    {
        $now = now();

        $source->forceFill([
            'last_fetched_at' => $now,
            'last_fetch_outcome' => $result->status,
            'last_error_type' => null,
            'last_error_message' => null,
            'last_successful_fetch_at' => $now,
            'consecutive_failures' => 0,
            'last_items_found' => $result->itemsFound,
            'last_valid_items' => $result->validItems,
            'last_duplicate_count' => $result->duplicateCount,
            'last_duration_ms' => $result->durationMs,
        ])->save();

        $log->forceFill([
            'type' => $runType,
            'status' => $result->status,
            'http_status' => $result->httpStatus,
            'items_found' => $result->itemsFound,
            'items_created' => $result->itemsCreated,
            'items_updated' => $result->itemsUpdated,
            'valid_items' => $result->validItems,
            'invalid_items' => $result->invalidItems,
            'missing_image_count' => $result->missingImageCount,
            'date_parse_error_count' => $result->dateParseErrorCount,
            'duplicate_count' => $result->duplicateCount,
            'duration_ms' => $result->durationMs,
            'error_type' => null,
            'error_message' => null,
            'finished_at' => $now,
        ])->save();
    }

    private function syncFailedRun(Source $source, SourceFetchLog $log, Carbon $startedAt, FeedFetchException $e): void
    {
        $now = now();
        $durationMs = $this->durationMs($startedAt);

        $source->forceFill([
            'last_fetch_outcome' => 'failed',
            'last_error_type' => $e->errorType,
            'last_error_message' => Str::limit($e->getMessage(), 1000),
            'last_failed_fetch_at' => $now,
            'consecutive_failures' => $source->consecutive_failures + 1,
            'last_duration_ms' => $durationMs,
        ])->save();

        $log->forceFill([
            'status' => 'failed',
            'error_type' => $e->errorType,
            'error_message' => Str::limit($e->getMessage(), 1000),
            'http_status' => $e->httpStatus,
            'duration_ms' => $durationMs,
            'finished_at' => $now,
        ])->save();
    }

    private function determineOutcomeStatus(array $metrics, int $durationMs): string
    {
        $warningConfig = config('source_health.warning');

        if ($metrics['items_found'] === 0) {
            return 'failed';
        }

        $validRate = $metrics['items_found'] > 0 ? $metrics['valid_items'] / $metrics['items_found'] : 0;
        $duplicateRate = $metrics['valid_items'] > 0 ? $metrics['duplicate_count'] / $metrics['valid_items'] : 0;

        if (
            $validRate < ($warningConfig['valid_item_rate_below'] ?? 0.8)
            || $duplicateRate > ($warningConfig['duplicate_rate_above'] ?? 0.9)
            || $durationMs > ($warningConfig['slow_duration_ms_above'] ?? 10000)
        ) {
            return 'warning';
        }

        return 'success';
    }

    private function durationMs(Carbon $startedAt): int
    {
        return max(1, $startedAt->diffInMilliseconds(now()));
    }

    private function uniqueSlug(string $title, string $url): string
    {
        return Str::slug($title).'-'.substr(md5($url), 0, 8);
    }

    private function extractDescription(\SimpleXMLElement $item): string
    {
        return $this->decode(strip_tags((string) $item->description));
    }

    private function decode(string $raw): string
    {
        return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractImage(\SimpleXMLElement $item): ?string
    {
        if (! empty($item->enclosure['url']) && str_contains((string) $item->enclosure['type'], 'image')) {
            return (string) $item->enclosure['url'];
        }

        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            if (isset($media->content['url'])) {
                return (string) $media->content['url'];
            }
        }

        $desc = (string) $item->description;
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function parseDate(string $raw): ?Carbon
    {
        if (blank($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
