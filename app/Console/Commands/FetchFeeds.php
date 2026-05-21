<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('feeds:fetch')]
#[Description('Fetch RSS feeds from all active sources and store new articles')]
class FetchFeeds extends Command
{
    public function handle(): int
    {
        $sources = Source::where('is_active', true)->with('category')->get();

        if ($sources->isEmpty()) {
            $this->warn('No active sources found. Add sources via the admin panel.');
            return self::SUCCESS;
        }

        $this->info("Fetching {$sources->count()} source(s)...");
        $totalNew = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($sources as $source) {
            try {
                [$new, $updated] = $this->fetchSource($source);
                $totalNew     += $new;
                $totalUpdated += $updated;

                $source->update(['last_fetched_at' => now()]);
                $this->line("  <fg=green>✓</> {$source->name}: {$new} new, {$updated} updated");
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
        Log::info("feeds:fetch completed: {$totalNew} new, {$totalUpdated} updated, {$totalFailed} failed.");

        return self::SUCCESS;
    }

    private function fetchSource(Source $source): array
    {
        $response = Http::timeout(15)->get($source->feed_url);

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }

        // Suppress XML warnings; handle malformed feeds gracefully
        $xml = @simplexml_load_string(
            $response->body(),
            'SimpleXMLElement',
            LIBXML_NOCDATA | LIBXML_NOERROR
        );

        if ($xml === false) {
            throw new \RuntimeException('Failed to parse XML feed');
        }

        $items = $xml->channel->item ?? [];
        $new = 0;
        $updated = 0;

        foreach ($items as $item) {
            $url = trim((string) $item->link);
            if (empty($url)) continue;

            $title = $this->decode((string) $item->title);
            if (empty($title)) continue;

            $slug = $this->uniqueSlug($title, $url);

            $result = Article::updateOrCreate(
                ['original_url' => $url],
                [
                    'source_id'   => $source->id,
                    'category_id' => $source->category_id,
                    'title'       => $title,
                    'slug'        => $slug,
                    'description' => $this->extractDescription($item),
                    'image_url'   => $this->extractImage($item),
                    'published_at' => $this->parseDate((string) $item->pubDate),
                ]
            );

            $result->wasRecentlyCreated ? $new++ : $updated++;
        }

        return [$new, $updated];
    }

    private function uniqueSlug(string $title, string $url): string
    {
        // Append 8-char URL hash to guarantee global uniqueness across sources
        return Str::slug($title) . '-' . substr(md5($url), 0, 8);
    }

    private function extractDescription(\SimpleXMLElement $item): string
    {
        return $this->decode(strip_tags((string) $item->description));
    }

    /**
     * Decode HTML entities (e.g. &agrave; → à, &amp; → &) and trim.
     * RSS feeds frequently encode Vietnamese diacritics as entities.
     */
    private function decode(string $raw): string
    {
        return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractImage(\SimpleXMLElement $item): ?string
    {
        // 1. <enclosure url="..." type="image/..."/>
        if (!empty($item->enclosure['url'])) {
            $url = (string) $item->enclosure['url'];
            if (str_contains((string) $item->enclosure['type'], 'image')) {
                return $url;
            }
        }

        // 2. <media:content url="..." medium="image"/>
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            if (isset($media->content['url'])) {
                return (string) $media->content['url'];
            }
        }

        // 3. First <img src="..."> inside description HTML
        $desc = (string) $item->description;
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $m)) {
            return $m[1];
        }

        return null;
    }

    private function parseDate(string $raw): ?Carbon
    {
        if (empty($raw)) return null;
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
