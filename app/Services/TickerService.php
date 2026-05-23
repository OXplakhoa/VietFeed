<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TickerService
{
    /** Cache-only read — never triggers upstream. Used for first-paint via view composer. */
    public function snapshot(): array
    {
        return [
            'usd' => Cache::get('ticker.usd.v1'),
            'sjc' => Cache::get('ticker.sjc.v1'),
        ];
    }

    /** Cache-aware fetch — hits upstream on cache miss. Used by /api/ticker endpoint. */
    public function fetch(): array
    {
        return [
            'usd' => $this->fetchMetric('ticker.usd.v1', 6 * 3600, fn () => $this->buildUsd()),
            'sjc' => $this->fetchMetric('ticker.sjc.v1', 5 * 60,   fn () => $this->buildSjc()),
        ];
    }

    private function fetchMetric(string $key, int $ttlSecs, callable $builder): ?array
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = null;
        try {
            $fresh = $builder();
        } catch (\Exception $e) {
            Log::error("Ticker [{$key}] fetch failed", ['err' => $e->getMessage()]);
        }

        if ($fresh !== null) {
            Cache::put($key, $fresh, $ttlSecs);
            Cache::put("{$key}.bak", $fresh, 86400);
            return $fresh;
        }

        $bak = Cache::get("{$key}.bak");
        return $bak ? array_merge($bak, ['stale' => true]) : null;
    }

    private function buildUsd(): ?array
    {
        $apiKey = config('services.exchangerate.key');
        if (!$apiKey) {
            Log::warning('Ticker: EXCHANGERATE_API_KEY not configured');
            return null;
        }

        $response = Http::timeout(8)->get("https://v6.exchangerate-api.com/v6/{$apiKey}/latest/USD");

        if (!$response->ok()) {
            Log::warning('Ticker: USD API error', ['status' => $response->status()]);
            return null;
        }

        $rate = (int) round($response->json('conversion_rates.VND', 0));
        if (!$rate) return null;

        // Build rolling 20-point history ourselves (API only gives current rate)
        $history   = Cache::get('ticker.usd.history.v1', []);
        $history[] = $rate;
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        Cache::put('ticker.usd.history.v1', $history, now()->addDays(30));

        $prev     = count($history) >= 2 ? $history[count($history) - 2] : $rate;
        $deltaPct = $prev > 0 ? round(($rate - $prev) / $prev * 100, 4) : 0;
        $trend    = count($history) < 2 ? 'up' : ($history[0] <= $rate ? 'up' : 'down');

        return [
            'value'      => $rate,
            'delta_pct'  => $deltaPct,
            'trend'      => $trend,
            'history'    => array_values($history),
            'updated_at' => now()->toIso8601String(),
            'source'     => 'exchangerate-api',
            'stale'      => false,
        ];
    }

    private function buildSjc(): ?array
    {
        $response = Http::timeout(8)->get('https://vang.today/api/prices', [
            'type' => 'SJL1L10',
            'days' => 7,
        ]);

        if (!$response->ok()) {
            Log::warning('Ticker: SJC API error', ['status' => $response->status()]);
            return null;
        }

        $raw = $response->json('history', []);
        if (empty($raw)) return null;

        $sorted = collect($raw)
            ->sortBy('date')
            ->values()
            ->toArray();

        $allSells = collect($sorted)
            ->map(fn ($d) => (int) ($d['prices']['SJL1L10']['sell'] ?? 0))
            ->filter()
            ->values()
            ->toArray();

        if (empty($allSells)) return null;

        $history      = $this->downsample($allSells, 20);
        $latest       = end($history);
        $prev         = count($history) >= 2 ? $history[count($history) - 2] : $latest;
        $deltaPct     = $prev > 0 ? round(($latest - $prev) / $prev * 100, 4) : 0;
        $trend        = $history[0] <= $latest ? 'up' : 'down';
        $latestEntry  = end($sorted);
        $buy          = (int) ($latestEntry['prices']['SJL1L10']['buy']  ?? 0);
        $sell         = (int) ($latestEntry['prices']['SJL1L10']['sell'] ?? 0);

        return [
            'buy'        => $buy,
            'sell'       => $sell,
            'delta_pct'  => $deltaPct,
            'trend'      => $trend,
            'history'    => $history,
            'updated_at' => now()->toIso8601String(),
            'source'     => 'vang.today',
            'stale'      => false,
        ];
    }

    private function downsample(array $values, int $target): array
    {
        $n = count($values);
        if ($n <= $target) return $values;

        $result = [];
        $step   = ($n - 1) / ($target - 1);
        for ($i = 0; $i < $target; $i++) {
            $result[] = $values[(int) round($i * $step)];
        }

        return $result;
    }
}
