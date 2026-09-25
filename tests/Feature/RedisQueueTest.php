<?php

namespace Tests\Feature;

use App\Jobs\FetchSourceFeedJob;
use App\Models\Category;
use App\Models\Source;
use App\Services\TickerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Gate 5.2 spike (GitHub #14): cache + queue on live Redis (CACHE_STORE=redis,
// QUEUE_CONNECTION=redis). Live-redis exception inside the suite (everything else
// uses array/sync fakes per phpunit.xml): requires compose redis healthy
// (`./scripts/nosql-health.sh`). Isolation: redis `default` + `cache` connections
// on DB 15 (dev uses DB 0/1), flushed in tearDown — never touches dev data.
// NO Queue::fake here by design (it would mask real dispatch failures). Run with:
//   vendor/bin/phpunit tests/Feature/RedisQueueTest.php
class RedisQueueTest extends TestCase
{
    use RefreshDatabase;

    private const FEED_XML = '<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Feed</title>
            <item><title>Bài 1</title><link>https://example.com/a</link><description><![CDATA[<img src="https://example.com/a.jpg">Mô tả]]></description><pubDate>Fri, 31 May 2026 10:00:00 GMT</pubDate></item>
            <item><title>Bài 2</title><link>https://example.com/b</link><description>Mô tả</description><pubDate>Fri, 31 May 2026 11:00:00 GMT</pubDate></item>
        </channel></rss>';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'database.redis.default.database' => 15,
            'database.redis.cache.database' => 15,
            'services.exchangerate.key' => 'test-fake-key',
        ]);

        Http::fake([
            'https://example.com/feed.xml' => Http::response(self::FEED_XML, 200),
            'https://v6.exchangerate-api.com/*' => Http::response(['conversion_rates' => ['VND' => 25000]], 200),
            'https://vang.today/*' => Http::response(['history' => [
                ['date' => '2026-05-30', 'prices' => ['SJL1L10' => ['sell' => 120000000]]],
                ['date' => '2026-05-31', 'prices' => ['SJL1L10' => ['sell' => 121000000]]],
            ]], 200),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection()->table('jobs')->delete();
            app('redis')->connection('default')->flushdb();
            app('redis')->connection('cache')->flushdb();
        } finally {
            parent::tearDown();
        }
    }

    private function makeSource(): Source
    {
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);

        return Source::create([
            'name' => 'Test Feed',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml',
            'category_id' => $category->id,
            'is_active' => true,
        ]);
    }

    public function test_fetch_job_dispatches_for_real_and_runs_on_redis_worker(): void
    {
        $source = $this->makeSource();
        $log = $source->fetchLogs()->create(['type' => 'manual_retry', 'status' => 'queued']);

        FetchSourceFeedJob::dispatch($source->id, $log->id);

        $this->assertSame(1, Queue::size());

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'default',
            '--once' => true,
        ]);

        $this->assertSame('success', $log->fresh()->status);
        $this->assertSame(2, (int) $log->fresh()->items_found);
        $this->assertSame(0, Queue::size());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_ticker_prewarms_redis_cache_keys(): void
    {
        $result = (new TickerService)->fetch();

        $this->assertSame(25000, $result['usd']['value']);
        $this->assertSame(121000000, $result['sjc']['sell']);
        $this->assertNotNull(Cache::get('ticker.usd.v1'));
        $this->assertNotNull(Cache::get('ticker.usd.v1.bak'));
        $this->assertNotNull(Cache::get('ticker.sjc.v1'));
        $this->assertNotEmpty(app('redis')->connection('cache')->keys('*ticker.*'));
    }
}
