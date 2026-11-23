<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use App\Jobs\FetchSourceFeedJob;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\SourceHistoryFixtures;
use Tests\TestCase;

class SourceHealthMonitorTest extends TestCase
{
    use SourceHistoryFixtures;

    public function test_admin_can_open_source_health_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::OPERATIONS]]);
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
        $history = $this->bootSourceHistory();
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::OPERATIONS]]);
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        $source = Source::create([
            'name' => 'Test Feed',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
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
        $history->consume('test', 10);
        $log = (array) $history->forSource($source->id, 1)->sole();
        $this->assertEquals([
            'source_id' => $source->id,
            'type' => 'rss_test',
            'status' => 'success',
            'items_found' => 2,
            'valid_items' => 2,
            'date_parse_error_count' => 1,
        ], array_intersect_key($log, array_flip(['source_id', 'type', 'status', 'items_found', 'valid_items', 'date_parse_error_count'])));
    }

    public function test_manual_retry_dispatches_job(): void
    {
        $history = $this->bootSourceHistory();
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::OPERATIONS]]);
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
        $history->consume('test', 10);
        $log = (array) $history->forSource($source->id, 1)->sole();
        $this->assertEquals([
            'source_id' => $source->id,
            'type' => 'manual_retry',
            'status' => 'queued',
        ], array_intersect_key($log, array_flip(['source_id', 'type', 'status'])));
        $this->assertNull($log['started_at']);
        Queue::assertPushed(FetchSourceFeedJob::class, fn ($job) => $job->attempt->id === $log['attempt_id']);
    }

    public function test_failed_dispatch_does_not_publish_queued_history(): void
    {
        $history = $this->bootSourceHistory();
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::OPERATIONS]]);
        $category = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        $source = Source::create([
            'name' => 'Test Feed', 'url' => 'https://example.com',
            'feed_url' => 'https://example.com/feed.xml', 'category_id' => $category->id,
        ]);

        $failure = new RuntimeException('Queue push failed');
        Bus::shouldReceive('dispatch')->once()->withArgs(fn (FetchSourceFeedJob $job) => $job->sourceId === $source->id)->andThrow($failure);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.sources.retry', $source));
            $this->fail('The dispatch should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(0, $history->stream()->lengths()['stream']);
    }
}
