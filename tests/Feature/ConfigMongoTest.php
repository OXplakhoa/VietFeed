<?php

namespace Tests\Feature;

use App\Content\CategoryList;
use App\Content\ContentCatalog;
use App\Jobs\FetchSourceFeedJob;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\Story;
use App\Models\User;
use App\SourceHistory\SourceFetchAttempt;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\Concerns\SourceHistoryFixtures;
use Tests\TestCase;

class ConfigMongoTest extends TestCase
{
    use GuardsRedisTestDatabase;
    use RefreshDatabase;
    use SourceHistoryFixtures;

    private function category(string $slug = 'topic'): Category
    {
        return Category::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function source(Category $category, string $feed = 'https://example.test/rss'): Source
    {
        return Source::create(['name' => 'Publisher', 'url' => 'https://example.test', 'feed_url' => $feed, 'category_id' => $category->id]);
    }

    public function test_admin_crud_validation_counts_and_string_relationships(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/admin/categories', ['name' => 'Topic', 'slug' => 'topic'])->assertRedirect()->assertSessionHasNoErrors();
        $category = Category::firstOrFail();
        $this->assertTrue(Str::isUuid($category->id, 7));
        $this->postJson('/admin/categories', ['name' => 'Another', 'slug' => 'topic'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->put('/admin/categories/'.$category->id, ['name' => 'Topic', 'slug' => 'topic'])->assertRedirect()->assertSessionHasNoErrors();
        $data = ['name' => 'Publisher', 'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss', 'category_id' => $category->id, 'is_active' => true];
        $this->post('/admin/sources', $data)->assertRedirect()->assertSessionHasNoErrors();
        $source = Source::firstOrFail();
        $this->assertTrue(Str::isUuid($source->id, 7));
        $this->assertSame($category->id, $source->category->id);
        $this->put('/admin/sources/'.$source->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/admin/sources', $data)->assertUnprocessable()->assertJsonValidationErrors('feed_url');
        $this->postJson('/admin/sources', array_replace($data, ['category_id' => 'missing', 'feed_url' => 'https://other.test/rss']))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $article = Article::create(['title' => 'Headline', 'slug' => 'headline', 'original_url' => 'https://example.test/a', 'source_id' => $source->id, 'category_id' => $category->id, 'published_at' => now()]);
        $this->assertSame($article->id, $category->articles->first()->id);
        $this->assertSame($source->id, $category->sources->first()->id);
        $this->assertSame($source->id, $article->source->id);
        $this->get('/admin/categories?sort=articles_count&dir=desc')->assertOk()->assertViewHas('categories', fn ($rows) => $rows->first()->articles_count === 1 && $rows->first()->sources_count === 1);
        foreach (['/admin/categories/'.$category->id.'/edit', '/admin/sources', '/admin/sources/'.$source->id, '/admin/sources/'.$source->id.'/edit', '/admin/sources/health?category='.$category->id, '/admin/dashboard'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->delete('/admin/categories/'.$category->id)->assertSessionHas('error');
        $history = $this->bootSourceHistory();
        $attempt = SourceFetchAttempt::begin($source->id, 'manual_retry', now());
        $history->publish($attempt, 0, ['status' => 'queued']);
        $history->consume('test', 10);
        $log = $this->get('/admin/sources/health')->assertOk()->viewData('recentLogs')->sole();
        $this->assertSame($source->id, $log->source->id);
        $this->assertSame($attempt->id, $log->attempt_id);
        $this->delete('/admin/sources/'.$source->id)->assertRedirect();
        $this->assertCount(0, $this->get('/admin/sources/health')->assertOk()->viewData('recentLogs'));
        $article->delete();
        $this->delete('/admin/categories/'.$category->id)->assertRedirect();
        $this->assertSame(0, Category::count());
    }

    public function test_redis_navigation_cache_rebuilds_and_invalidates_on_all_category_writes(): void
    {
        $this->isolateRedis(['cache']);
        $this->flushTestDatabase(Redis::connection('cache'));
        config(['cache.default' => 'redis']);
        try {
            $list = app(CategoryList::class);
            $this->assertCount(0, $list->active());
            $category = $this->category();
            $this->assertSame([$category->id], $list->active()->modelKeys());
            $category->update(['name' => 'Renamed']);
            $this->assertSame('Renamed', $list->active()->first()->name);
            $category->update(['is_active' => false]);
            $this->assertCount(0, $list->active());
            $category->update(['is_active' => true]);
            Cache::forget(CategoryList::KEY);
            $this->assertSame([$category->id], $list->active()->modelKeys());
            $category->delete();
            $this->assertCount(0, $list->active());
            $this->get('/pricing')->assertOk()->assertDontSee('Renamed');
        } finally {
            $this->flushTestDatabase(Redis::connection('cache'));
            config(['cache.default' => 'array']);
        }
    }

    public function test_interests_validate_active_mongo_categories_and_embed_strings(): void
    {
        $user = User::factory()->create();
        $category = $this->category();
        $disabled = $this->category('disabled');
        $disabled->update(['is_active' => false]);
        $this->actingAs($user)->post('/onboarding/interests', ['categories' => [$category->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$category->id], $user->fresh()->favorite_category_ids);
        $this->putJson('/profile/preferences', ['categories' => [$disabled->id]])->assertUnprocessable();
        $this->put('/profile/preferences', ['categories' => [$category->id]])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_sql_import_is_rerunnable_and_remaps_references_without_changing_snapshots(): void
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Legacy', 'slug' => 'legacy', 'is_active' => true]);
        $sourceId = DB::table('sources')->insertGetId(['name' => 'Legacy source', 'url' => 'https://legacy.test', 'feed_url' => 'https://legacy.test/rss', 'category_id' => $categoryId, 'prestige' => 5, 'consecutive_failures' => 3, 'last_failed_fetch_at' => '2026-11-17 09:00:00', 'created_at' => '2026-11-01 10:00:00']);
        $articles = collect();
        foreach ([$categoryId, (string) $categoryId] as $i => $id) {
            $article = Article::create(['title' => 'Legacy '.$i, 'slug' => 'legacy-'.$i, 'original_url' => 'https://legacy.test/'.$i, 'category_id' => $id, 'source_id' => $i ? (string) $sourceId : $sourceId]);
            $article->forceFill(['category_snapshot' => ['slug' => 'legacy', 'uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:category:legacy')->toString()], 'source_snapshot' => ['feed_url' => 'https://legacy.test/rss', 'uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:source:https://legacy.test/rss')->toString()]])->save();
            $articles->push($article);
        }
        $user = User::factory()->create(['favorite_category_ids' => [$categoryId, (string) $categoryId]]);
        $logId = DB::table('source_fetch_logs')->insertGetId(['source_id' => (string) $sourceId, 'type' => 'scheduled_fetch', 'status' => 'success']);
        // An interrupted earlier copy is reused rather than duplicated or overwritten.
        $category = $this->category('legacy');
        $this->artisan('config:import-sql')->expectsOutput('categories_matched: 1')->expectsOutput('sources_copied: 1')->assertExitCode(0);
        $source = Source::firstOrFail();
        $this->assertTrue(Str::isUuid($source->id, 7));
        $this->assertSame($category->id, $source->category_id);
        $this->assertSame(5, $source->prestige);
        $this->assertSame('critical', $source->health_status);
        $this->assertSame('2026-11-01 10:00:00', $source->created_at->format('Y-m-d H:i:s'));
        foreach ($articles as $article) {
            $fresh = $article->fresh();
            $this->assertSame($category->id, $fresh->category_id);
            $this->assertSame($source->id, $fresh->source_id);
            $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:source:https://legacy.test/rss')->toString(), $fresh->source_snapshot['uuid']);
            $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:category:legacy')->toString(), $fresh->category_snapshot['uuid']);
        }
        $this->assertSame([$category->id], $user->fresh()->favorite_category_ids);
        $this->assertSame((string) $sourceId, DB::table('source_fetch_logs')->where('id', $logId)->value('source_id'));
        $source->update(['name' => 'Edited after import']);
        $this->artisan('config:import-sql')->expectsOutput('sources_copied: 0')->expectsOutput('article_references: 0')->expectsOutput('users_remapped: 0')->assertExitCode(0);
        $this->assertSame('Edited after import', $source->fresh()->name);
        $this->assertSame(1, Category::count());
        $this->assertSame(1, Source::count());
        $this->assertSame(1, DB::table('categories')->count());
        $this->assertSame(1, DB::table('sources')->count());
    }

    public function test_sql_import_allocates_category_uuid_and_preserves_disabled_state(): void
    {
        DB::table('categories')->insert(['name' => 'Disabled legacy', 'slug' => 'disabled-legacy', 'is_active' => false]);
        $this->artisan('config:import-sql')->expectsOutput('categories_copied: 1')->assertExitCode(0);
        $category = Category::firstOrFail();
        $this->assertTrue(Str::isUuid($category->id, 7));
        $this->assertFalse($category->is_active);
        $this->artisan('config:import-sql')->expectsOutput('categories_copied: 0')->expectsOutput('categories_matched: 1')->assertExitCode(0);
        $this->assertSame($category->id, Category::firstOrFail()->id);
    }

    public function test_indexes_are_idempotent_and_homepage_can_have_multiple_feeds(): void
    {
        $migration = require database_path('migrations/2026_11_18_100001_create_mongodb_config_indexes.php');
        $migration->up();
        $migration->up();
        $category = $this->category();
        $this->source($category);
        $this->source($category, 'https://example.test/another-rss');
        foreach (['categories' => 'slug_1', 'sources' => 'feed_url_1'] as $collection => $name) {
            $index = collect(DB::connection('mongodb')->getCollection($collection)->listIndexes())->first(fn ($index) => $index->getName() === $name);
            $this->assertTrue($index->isUnique());
        }
        try {
            $this->source($category);
            $this->fail('Duplicate feed_url must fail.');
        } catch (BulkWriteException $error) {
            $this->assertSame(11000, $error->getCode());
        }
        $this->assertSame(2, Source::count());
        try {
            $this->category();
            $this->fail('Duplicate slug must fail.');
        } catch (BulkWriteException $error) {
            $this->assertSame(11000, $error->getCode());
        }
    }

    public function test_rss_command_and_job_keep_uuid_references_in_articles_and_history(): void
    {
        $history = $this->bootSourceHistory();
        $source = $this->source($this->category());
        Http::fake(['*' => Http::response('<rss version="2.0"><channel><title>Feed</title><item><title>Headline</title><link>https://example.test/article</link><description>Excerpt</description><pubDate>Wed, 18 Nov 2026 10:00:00 GMT</pubDate></item></channel></rss>', 200)]);
        $this->artisan('feeds:fetch')->assertExitCode(0);
        $article = Article::firstOrFail();
        $this->assertSame($source->id, $article->source_id);
        $this->assertSame($source->category_id, $article->category_id);
        $job = new FetchSourceFeedJob($source->id);
        app()->call([$job, 'handle']);
        $history->consume('test', 10);
        $logs = $history->forSource($source->id, 5)->keyBy('type');
        $this->assertSame($source->id, $logs['scheduled_fetch']->source_id);
        $this->assertSame($job->attempt->id, $logs['manual_retry']->attempt_id);
        $this->assertSame('warning', $logs['manual_retry']->status);
        $this->assertSame(1, Article::count());
    }

    public function test_public_and_admin_reads_never_query_sql_config_and_are_batched(): void
    {
        $category = $this->category();
        $source = $this->source($category);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $measurements = [];
        foreach ([2, 8] as $size) {
            foreach (range($size === 2 ? 1 : 3, $size) as $i) {
                Article::create(['title' => 'Headline '.$i, 'slug' => 'headline-'.$i, 'original_url' => 'https://example.test/'.$i, 'source_id' => $source->id, 'category_id' => $category->id, 'image_url' => 'https://example.test/image.jpg', 'published_at' => now()]);
            }
            foreach (['/', '/categories/topic', '/search?q=Headline', '/articles', '/api/articles?page=1', '/api/live-search?q=Headline', '/admin/categories', '/admin/sources', '/admin/sources/health', '/admin/dashboard'] as $url) {
                DB::connection()->enableQueryLog();
                DB::connection()->flushQueryLog();
                try {
                    $this->get($url)->assertOk();
                    $queries = DB::connection()->getQueryLog();
                    foreach ($queries as $query) {
                        $this->assertDoesNotMatchRegularExpression('/\b(?:from|join|into|update)\s+["`]?\b(?:categories|sources)\b/i', $query['query']);
                    }
                    $measurements[$size][$url] = count($queries);
                } finally {
                    DB::connection()->disableQueryLog();
                }
            }
        }
        $this->assertSame($measurements[2], $measurements[8]);
        // Static guard complements executed query evidence and covers validation paths.
        $offlineImports = [app_path('Content/ConfigSqlImport.php'), app_path('SourceHistory/SourceHistorySqlImport.php')];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getPathname(), $offlineImports, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/(?:DB::table|->table)\([\'\"](?:categories|sources)[\'\"]\)/', file_get_contents($file->getPathname()), $file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/(?:exists|unique):(?:categories|sources),/', file_get_contents($file->getPathname()), $file->getPathname());
        }
    }

    public function test_catalog_snapshot_identity_and_source_count_are_independent_of_configuration(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        $item = $fixture['items'][0];
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
        try {
            app(ContentCatalog::class)->ingest($fixture);
            $article = Article::firstOrFail();
            $sourceUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:source:'.$item['source']['feed_url'])->toString();
            $categoryUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:category:'.$item['category']['slug'])->toString();
            $this->assertSame($sourceUuid, $article->source_snapshot['uuid']);
            $this->assertSame($categoryUuid, $article->category_snapshot['uuid']);
            $this->assertSame(1, Story::firstOrFail()->source_count);
            $category = $this->category($item['category']['slug']);
            $source = $this->source($category, $item['source']['feed_url']);
            // Same feed, a new item in the same Story after canonical config exists.
            $fixture['items'][0]['link'] .= '?second-item=1';
            app(ContentCatalog::class)->ingest($fixture);
            $second = Article::where('original_url', $fixture['items'][0]['link'])->firstOrFail();
            $this->assertSame($sourceUuid, $second->source_snapshot['uuid']);
            $this->assertSame($categoryUuid, $second->category_snapshot['uuid']);
            $this->assertNotSame($source->id, $sourceUuid);
            $this->assertSame(1, Story::firstOrFail()->source_count);
            $this->assertSame(2, Story::firstOrFail()->article_count);
            $this->assertNull($article->source_id);
            $this->get('/articles/'.$article->slug)->assertNotFound();
        } finally {
            $this->flushTestDatabase(Redis::connection('nosql'));
        }
    }

    public function test_real_admin_checkbox_payloads_store_bools_on_create_and_update(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([true, false] as $checked) {
            // PHP parses duplicate browser form keys using the final value.
            parse_str('is_active=0'.($checked ? '&is_active=1' : ''), $checkbox);
            $slug = $checked ? 'checked' : 'unchecked';
            $categoryData = ['name' => ucfirst($slug), 'slug' => $slug] + $checkbox;
            $this->post('/admin/categories', $categoryData)->assertRedirect()->assertSessionHasNoErrors();
            $category = Category::where('slug', $slug)->firstOrFail();
            $this->assertStoredActive($category, $checked);
            $sourceData = ['name' => 'Publisher '.$slug, 'url' => 'https://example.test', 'feed_url' => 'https://example.test/'.$slug, 'category_id' => $category->id, 'prestige' => '5'] + $checkbox;
            $this->post('/admin/sources', $sourceData)->assertRedirect()->assertSessionHasNoErrors();
            $source = Source::where('feed_url', $sourceData['feed_url'])->firstOrFail();
            $this->assertStoredActive($source, $checked);
            $this->assertSame(5, DB::connection('mongodb')->getCollection('sources')->findOne(['_id' => $source->id])['prestige']);
            parse_str('is_active=0'.(! $checked ? '&is_active=1' : ''), $opposite);
            $this->put('/admin/categories/'.$category->id, array_replace($categoryData, $opposite))->assertRedirect()->assertSessionHasNoErrors();
            $this->put('/admin/sources/'.$source->id, array_replace($sourceData, $opposite))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertStoredActive($category, ! $checked);
            $this->assertStoredActive($source, ! $checked);
        }
    }

    private function assertStoredActive(Category|Source $model, bool $expected): void
    {
        $raw = DB::connection('mongodb')->getCollection($model->getTable())->findOne(['_id' => $model->id]);
        $this->assertIsBool($raw['is_active']);
        $this->assertSame($expected, $raw['is_active']);
        $this->assertSame($expected, $model::active()->where('_id', $model->id)->exists());
    }

    public function test_model_writes_normalize_flags_and_nullable_numeric_health_fields(): void
    {
        $category = Category::create(['name' => 'Types', 'slug' => 'types', 'is_active' => '1']);
        $source = $this->source($category);
        foreach (['5', null] as $value) {
            $fields = array_fill_keys(['prestige', 'consecutive_failures', 'last_items_found', 'last_valid_items', 'last_duplicate_count', 'last_duration_ms'], $value);
            $source->update($fields + ['is_active' => '0']);
            $raw = DB::connection('mongodb')->getCollection('sources')->findOne(['_id' => $source->id]);
            foreach ($fields as $key => $input) {
                $this->assertSame($input === null ? null : 5, $raw[$key], $key);
            }
            $this->assertStoredActive($source, false);
        }
        $this->assertStoredActive($category, true);
    }

    public function test_import_sql_integer_flags_become_queryable_bson_bools(): void
    {
        foreach ([1, 0] as $flag) {
            $id = DB::table('categories')->insertGetId(['name' => 'Imported '.$flag, 'slug' => 'imported-'.$flag, 'is_active' => $flag]);
            DB::table('sources')->insert(['name' => 'Imported '.$flag, 'url' => 'https://example.test', 'feed_url' => 'https://example.test/imported-'.$flag, 'category_id' => $id, 'is_active' => $flag]);
        }
        $this->artisan('config:import-sql')->assertExitCode(0);
        $this->assertSame(1, Category::active()->count());
        $this->assertSame(1, Source::active()->count());
        foreach (Category::all() as $category) {
            $this->assertStoredActive($category, $category->slug === 'imported-1');
        }
        foreach (Source::all() as $source) {
            $this->assertStoredActive($source, str_ends_with($source->feed_url, '-1'));
        }
    }

    public function test_import_duplicate_natural_keys_abort_before_any_write_and_only_list_ids(): void
    {
        // Simulate a legacy schema whose slug uniqueness was not enforced.
        Schema::table('categories', fn ($table) => $table->dropUnique(['slug']));
        $a = DB::table('categories')->insertGetId(['name' => 'A', 'slug' => 'duplicate']);
        $b = DB::table('categories')->insertGetId(['name' => 'B', 'slug' => 'duplicate']);
        $x = DB::table('sources')->insertGetId(['name' => 'X', 'url' => 'https://example.test', 'feed_url' => 'https://private.example.test/secret-feed', 'category_id' => $a]);
        $y = DB::table('sources')->insertGetId(['name' => 'Y', 'url' => 'https://example.test', 'feed_url' => 'https://private.example.test/secret-feed', 'category_id' => $b]);
        $article = Article::create(['title' => 'Legacy', 'source_id' => $x, 'category_id' => $a]);
        $user = User::factory()->create(['favorite_category_ids' => [$a]]);
        $logId = DB::table('source_fetch_logs')->insertGetId(['source_id' => (string) $x, 'type' => 'scheduled_fetch', 'status' => 'success']);
        $this->artisan('config:import-sql')
            ->expectsOutput('categories.slug duplicate ids: '.$a.', '.$b.'; sources.feed_url duplicate ids: '.$x.', '.$y)->assertExitCode(1);
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Source::count());
        $this->assertSame($x, $article->fresh()->source_id);
        $this->assertSame([$a], $user->fresh()->favorite_category_ids);
        $this->assertSame((string) $x, DB::table('source_fetch_logs')->where('id', $logId)->value('source_id'));
        // Exact output above contains IDs only, never the private feed URL.
        // Source duplicates alone must still abort before even a category copy.
        DB::table('categories')->where('id', $b)->update(['slug' => 'other']);
        $this->artisan('config:import-sql')->expectsOutput('sources.feed_url duplicate ids: '.$x.', '.$y)->assertExitCode(1);
        $this->assertSame(0, Category::count());
        DB::table('sources')->where('id', $y)->update(['feed_url' => 'https://example.test/unique']);
        DB::table('categories')->where('id', $b)->update(['slug' => 'duplicate']);
        $this->artisan('config:import-sql')->expectsOutput('categories.slug duplicate ids: '.$a.', '.$b)->assertExitCode(1);
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Source::count());
    }

    public function test_health_limits_cassandra_history_per_source_and_globally(): void
    {
        $history = $this->bootSourceHistory();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $category = $this->category();
        $expected = [];
        $at = now()->utc()->startOfDay()->addHours(2);
        foreach (['first', 'second'] as $feed) {
            $source = $this->source($category, 'https://example.test/'.$feed);
            foreach (range(1, 25) as $i) {
                $started = $at->copy()->subMinutes($i);
                $attempt = SourceFetchAttempt::begin($source->id, 'scheduled_fetch', $started);
                $expected[$source->id][] = $attempt->id;
                $history->publish($attempt, 2, ['status' => 'success', 'fetch_started_at' => $started]);
            }
        }
        $this->assertSame(50, $history->consume('test', 100));

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $response = $this->get('/admin/sources/health')->assertOk();
            foreach ($response->viewData('sources') as $source) {
                $this->assertSame(array_slice($expected[$source->id], 0, 5), $source->fetchLogs->pluck('attempt_id')->all());
                $show = $this->get(route('admin.sources.show', $source))->assertOk();
                $this->assertSame(array_slice($expected[$source->id], 0, 15), $show->viewData('recentLogs')->pluck('attempt_id')->all());
            }
            $recent = $response->viewData('recentLogs');
            $this->assertCount(20, $recent);
            $this->assertSame($recent->pluck('started_at')->sortDesc()->values()->all(), $recent->pluck('started_at')->all());
            $this->assertFalse(collect(DB::connection()->getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'source_fetch_logs')));
        } finally {
            DB::connection()->disableQueryLog();
        }
    }

    public function test_unchanged_seeders_keep_stable_ids_and_store_queryable_types(): void
    {
        $this->seed([CategorySeeder::class, SourceSeeder::class]);
        $ids = Source::pluck('id', 'feed_url')->all();
        $this->seed([CategorySeeder::class, SourceSeeder::class]);
        $this->assertSame(8, Category::active()->count());
        $this->assertSame(26, Source::active()->count());
        $this->assertSame($ids, Source::pluck('id', 'feed_url')->all());
        foreach (DB::connection('mongodb')->getCollection('sources')->find() as $source) {
            $this->assertIsBool($source['is_active']);
            $this->assertIsInt($source['prestige']);
        }
    }

    public function test_layout_and_category_writes_survive_a_failing_cache_store(): void
    {
        Cache::extend('broken-config-cache', fn () => new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('Cache unavailable');
            }

            public function forget($key)
            {
                throw new \RuntimeException('Cache unavailable');
            }
        });
        config(['cache.stores.broken-config-cache' => ['driver' => 'broken-config-cache'], 'cache.default' => 'broken-config-cache']);
        $category = $this->category('cache-fallback');
        $this->assertSame([$category->id], app(CategoryList::class)->active()->modelKeys());
        $this->get('/pricing')->assertOk()->assertSee('Cache-fallback');
        $category->update(['name' => 'Still visible']);
        $this->get('/pricing')->assertOk()->assertSee('Still visible');
        $category->delete();
        $this->get('/pricing')->assertOk()->assertDontSee('Still visible');
    }
}
