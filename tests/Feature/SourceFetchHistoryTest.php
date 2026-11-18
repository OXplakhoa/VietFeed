<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\Rss\Exceptions\FeedFetchException;
use App\Services\Rss\FeedIngestionService;
use App\SourceHistory\SourceFetchAttempt;
use App\SourceHistory\SourceFetchHistory;
use App\StoryProcessing\CassandraClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SourceHistoryFixtures;
use Tests\TestCase;

class SourceFetchHistoryTest extends TestCase
{
    use SourceHistoryFixtures;

    private const FEED = '<rss version="2.0"><channel><title>Publisher</title><item><title>Headline</title>
        <link>https://example.test/story</link><description>Excerpt</description></item></channel></rss>';

    private function source(): Source
    {
        $category = Category::create(['name' => 'History', 'slug' => 'history']);

        return Source::create([
            'name' => 'Publisher', 'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss',
            'category_id' => $category->id,
        ]);
    }

    public function test_fetch_outcomes_project_to_both_tables_and_update_mongo_without_sql(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $service = app(FeedIngestionService::class);
        Http::preventStrayRequests();
        Http::fake([$source->feed_url => Http::sequence()->push(self::FEED)->push(self::FEED)->push('', 503)]);
        $this->travelTo(now()->startOfSecond());

        DB::connection('mongodb')->enableQueryLog();
        try {
            $this->assertSame('success', $service->fetchSource($source)->status);
            $this->assertSame('success', $source->fresh()->last_fetch_outcome);
            $this->travel(1)->seconds();
            $this->assertSame('warning', $service->fetchSource($source)->status);
            $this->assertSame('warning', $source->fresh()->last_fetch_outcome);

            $this->travel(1)->seconds();
            try {
                $service->fetchSource($source);
                $this->fail('The RSS failure must propagate.');
            } catch (FeedFetchException $e) {
                $this->assertTrue($e->retryable);
                $this->assertSame(503, $e->httpStatus);
            }
            $summary = $source->fresh();
            $this->assertSame('failed', $summary->last_fetch_outcome);
            $this->assertSame(1, $summary->consecutive_failures);

            $this->assertSame(6, $history->consume('test', 10));
            $rows = $history->forSource($source->id, 5);
            $global = $history->global(10, fn ($id) => $source->id === $id);
            $this->assertEquals($rows->all(), $global->all());
            $this->assertSame(['failed', 'warning', 'success'], $rows->pluck('status')->all());
            $this->assertTrue($rows->every(fn ($row) => Str::isUuid($row->attempt_id, 7) && $row->finished_at !== null));
            $this->assertSame('HTTP 503', $rows[0]->error_message);
            $this->assertEquals(1, $rows[1]->duplicate_count);
            $this->assertEquals(1, $rows[2]->items_created);
            $this->assertFalse(collect(DB::connection('mongodb')->getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'source_fetch_logs')));
        } finally {
            DB::connection('mongodb')->disableQueryLog();
        }
    }

    public function test_unavailable_cassandra_leaves_rss_and_mongo_working_and_replays_history(): void
    {
        $this->bootSourceHistory();
        $source = $this->source();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::preventStrayRequests();
        Http::fake([$source->feed_url => Http::response(self::FEED)]);

        $endpoint = config('nosql.cassandra');
        config(['nosql.cassandra.host' => '127.0.0.1', 'nosql.cassandra.port' => 1]);
        $history = app(SourceFetchHistory::class);
        $this->assertSame('success', app(FeedIngestionService::class)->fetchSource($source)->status);
        $this->assertSame('success', $source->fresh()->last_fetch_outcome);
        $this->assertSame(2, $history->stream()->lengths()['stream']);
        foreach (['admin.sources.health' => [], 'admin.sources.show' => [$source]] as $route => $parameters) {
            $response = $this->get(route($route, $parameters))->assertOk()
                ->assertSee('Lịch sử lấy tin tạm thời không khả dụng.')
                ->assertSee('Publisher')
                ->assertSee('Retry fetch');
            $this->assertFalse($response->viewData('historyAvailable'));
            $this->assertCount(0, $response->viewData('recentLogs'));
        }

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(1, $history->consume('outage', 1));
            usleep(20_000);
        }
        $dead = $history->stream()->deadLetters();
        $this->assertCount(2, $dead);
        foreach ($dead as $id => $entry) {
            $this->assertSame('Source fetch history projection failed (RuntimeException)', $entry['last_error']);
            $this->artisan('sources:history-manage', ['action' => 'replay', '--id' => $id])->assertSuccessful();
        }

        config(['nosql.cassandra' => $endpoint]);
        $this->artisan('sources:history-manage', ['action' => 'consume'])->expectsOutput('Processed: 2')->assertSuccessful();
        $response = $this->get(route('admin.sources.show', $source))->assertOk()
            ->assertDontSee('Lịch sử lấy tin tạm thời không khả dụng.')
            ->assertSee('scheduled_fetch');
        $this->assertSame('success', $response->viewData('recentLogs')->sole()->status);
    }

    public function test_newest_first_across_utc_midnight_and_demo_windows(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $midnight = now()->utc()->startOfDay();
        $this->travelTo($midnight->copy()->addMinutes(5));
        $times = collect([
            $midnight->copy()->addMilliseconds(50),
            $midnight->copy()->subMilliseconds(50),
            $midnight->copy()->subDays(6),
            $midnight->copy()->subDays(7),
        ]);
        $ids = $times->map(function ($at) use ($source, $history) {
            $attempt = SourceFetchAttempt::begin($source->id, 'scheduled_fetch', $at);
            $history->publish($attempt, 2, ['status' => 'success', 'fetch_started_at' => $at]);

            return $attempt->id;
        })->all();
        $this->assertSame(4, $history->consume('test', 10));
        $this->assertSame($ids, $history->forSource($source->id, 15)->pluck('attempt_id')->all());
        $this->assertSame($ids, $history->global(4, fn ($id) => $source->id === $id)->pluck('attempt_id')->all());
        $this->artisan('sources:history', ['--source' => $source->id, '--days' => 1])
            ->expectsOutputToContain($ids[0])->doesntExpectOutputToContain($ids[1])->assertSuccessful();
        $this->artisan('sources:history', ['--source' => $source->id, '--days' => 7])
            ->expectsOutputToContain($ids[0])->expectsOutputToContain($ids[1])->expectsOutputToContain($ids[2])
            ->doesntExpectOutputToContain($ids[3])->assertSuccessful();
        $this->artisan('sources:history', ['--source' => $source->id, '--days' => 0])->assertExitCode(2);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $health = $this->get(route('admin.sources.health'))->assertOk();
        $recentIds = array_slice($ids, 0, 3);
        $this->assertSame($recentIds, $health->viewData('recentLogs')->pluck('attempt_id')->all());
        $this->assertSame($recentIds, $health->viewData('sources')->first()->fetchLogs->pluck('attempt_id')->all());
        $response = $this->get(route('admin.sources.show', $source))->assertOk();
        $this->assertSame($ids, $response->viewData('recentLogs')->pluck('attempt_id')->all());
        $this->assertSame($midnight->format('Y-m-d'), $response->viewData('recentLogs')->first()->day);
        $this->assertSame($midnight->copy()->subDay()->format('Y-m-d'), $response->viewData('recentLogs')[1]->day);
    }

    public function test_older_retry_snapshots_cannot_overwrite_a_newer_result(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $now = now()->startOfSecond();
        $attempt = SourceFetchAttempt::begin($source->id, 'manual_retry', $now);
        $success = [
            'status' => 'success', 'items_found' => 3, 'http_status' => 200,
            'finished_at' => $now->copy()->addSeconds(12), 'error_type' => null, 'error_message' => null,
        ];
        $history->publish($attempt, 4, $success);
        $history->publish($attempt, 3, ['status' => 'running']);
        $history->publish($attempt, 2, [
            'status' => 'failed', 'error_type' => 'http_error', 'error_message' => 'HTTP 503',
            'http_status' => 503, 'fetch_started_at' => $now,
        ]);
        $history->publish($attempt, 0, ['status' => 'queued']);
        $history->publish($attempt, 4, $success);

        $this->assertSame(5, $history->consume('test', 10));
        $row = $history->forSource($source->id, 5)->sole();
        $this->assertEquals($row, $history->global(5, fn ($id) => $source->id === $id)->sole());
        $this->assertSame($attempt->id, $row->attempt_id);
        $this->assertSame('success', $row->status);
        $this->assertEquals(3, $row->items_found);
        $this->assertNull($row->error_type);
        $this->assertNull($row->error_message);
        $this->assertEquals(200, $row->http_status);
        $this->assertTrue($row->finished_at->equalTo($success['finished_at']));
        $this->assertTrue($row->started_at->equalTo($now));
        $client = app(CassandraClient::class);
        $this->assertSame('vietfeed_test', $client->keyspace());
        foreach ([SourceFetchHistory::BY_SOURCE, SourceFetchHistory::GLOBAL] as $table) {
            $schema = $client->rows("SELECT gc_grace_seconds FROM system_schema.tables WHERE keyspace_name = 'vietfeed_test' AND table_name = ?", [$table]);
            $this->assertEquals(SourceFetchHistory::RETENTION_SECONDS, $schema[0]['gc_grace_seconds']);
        }
    }

    public function test_sparse_queued_and_running_snapshots_leave_outcome_columns_untouched(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $now = now()->startOfSecond();
        $attempt = SourceFetchAttempt::begin($source->id, 'manual_retry', $now);
        $unset = ['http_status' => null, 'error_type' => null, 'error_message' => null, 'finished_at' => null];
        $history->publish($attempt, 2, ['status' => 'queued'] + $unset);
        $history->publish($attempt, 3, ['status' => 'running'] + $unset);

        // An older outcome can fill untouched cells, but cannot change the newer lifecycle status.
        // Tombstones written by either snapshot would suppress these older values.
        $history->publish($attempt, 1, [
            'status' => 'failed', 'http_status' => 503, 'error_type' => 'http_error',
            'error_message' => 'HTTP 503', 'finished_at' => $now, 'items_found' => 0,
        ]);
        $this->assertSame(3, $history->consume('test', 10));

        $row = $history->forSource($source->id, 5)->sole();
        $this->assertEquals($row, $history->global(5, fn ($id) => $source->id === $id)->sole());
        $this->assertSame('running', $row->status);
        $this->assertEquals(503, $row->http_status);
        $this->assertEquals(0, $row->items_found);
        $this->assertSame('http_error', $row->error_type);
        $this->assertSame('HTTP 503', $row->error_message);
        $this->assertTrue($row->finished_at->equalTo($now));
    }

    public function test_history_publish_scrubs_invalid_utf8_and_projects_the_outcome(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $attempt = SourceFetchAttempt::begin($source->id, 'scheduled_fetch', now());
        $history->publish($attempt, 1, ['status' => 'running']);
        $history->publish($attempt, 2, [
            'status' => 'failed', 'error_type' => 'http_error', 'error_message' => "Upstream \xFF response",
        ]);

        $this->assertSame(2, $history->consume('test', 10));
        $row = $history->forSource($source->id, 1)->sole();
        $this->assertSame('failed', $row->status);
        $this->assertTrue(mb_check_encoding($row->error_message, 'UTF-8'));
        $this->assertSame(mb_scrub("Upstream \xFF response", 'UTF-8'), $row->error_message);
    }

    public function test_consumer_budget_stops_before_claiming_another_delivery(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source();
        $attempt = SourceFetchAttempt::begin($source->id, 'scheduled_fetch', now());
        $history->publish($attempt, 1, ['status' => 'running']);
        $history->publish($attempt, 2, ['status' => 'success']);
        $remainingChecks = 1;

        $this->assertSame(1, $history->consume('test', 10, function () use (&$remainingChecks) {
            return $remainingChecks-- > 0;
        }));

        $this->assertSame('running', $history->forSource($source->id, 1)->sole()->status);
        $this->assertSame(0, $history->stream()->lengths()['pending']);
        $this->assertSame(1, $history->stream()->lengths()['stream']);
        $this->artisan('sources:history-manage', ['action' => 'consume'])->expectsOutput('Processed: 1')->assertSuccessful();
        $this->assertSame('success', $history->forSource($source->id, 1)->sole()->status);
    }
}
