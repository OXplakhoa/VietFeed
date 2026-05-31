<?php

namespace Tests\Feature;

use App\Jobs\FetchSourceFeedJob;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceHealthMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_source_health_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        Source::create([
            'name' => 'Test Feed',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sources.health'))
            ->assertOk()
            ->assertSee('Source Health Monitor')
            ->assertSee('Test Feed');
    }

    public function test_rss_test_logs_diagnostics_without_updating_source_health(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        $source = Source::create([
            'name' => 'Test Feed',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.com/feed.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <rss version="2.0"><channel><title>Feed</title>
                    <item><title>Bài 1</title><link>https://example.com/a</link><description><![CDATA[<img src="https://example.com/a.jpg">Mô tả]]></description><pubDate>Fri, 31 May 2026 10:00:00 GMT</pubDate></item>
                    <item><title>Bài 2</title><link>https://example.com/b</link><description>Mô tả</description><pubDate>bad-date</pubDate></item>
                </channel></rss>', 200),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.sources.test', $source))
            ->assertRedirect();

        $source->refresh();

        $this->assertNull($source->last_fetch_outcome);
        $this->assertDatabaseHas('source_fetch_logs', [
            'source_id' => $source->id,
            'type' => 'rss_test',
            'status' => 'success',
            'items_found' => 2,
            'valid_items' => 2,
            'date_parse_error_count' => 1,
        ]);
    }

    public function test_manual_retry_dispatches_job(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        $source = Source::create([
            'name' => 'Test Feed',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.sources.retry', $source))
            ->assertRedirect();

        Queue::assertPushed(FetchSourceFeedJob::class);
        $this->assertDatabaseHas('source_fetch_logs', [
            'source_id' => $source->id,
            'type' => 'manual_retry',
            'status' => 'queued',
        ]);
    }
}
