<?php

namespace Tests\Feature;

use App\Billing\BillingGateway;
use App\Content\ContentCatalog;
use App\Http\Controllers\Admin\ArticleController;
use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class CatalogArticlePagesTest extends TestCase
{
    use GuardsRedisTestDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        app(ContentCatalog::class)->ingest($fixture);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabase(Redis::connection('nosql'));
        parent::tearDown();
    }

    public function test_admin_can_read_ingested_article_without_sql_relations(): void
    {
        $article = Article::firstOrFail();
        foreach (['/admin/dashboard', '/admin/articles', '/admin/articles/'.$article->id] as $url) {
            $this->get($url)->assertOk()->assertSee('VnExpress')->assertSee('Thế giới');
        }
    }

    public function test_admin_can_edit_catalog_metadata_without_assigning_sql_relations(): void
    {
        $article = Article::firstOrFail();
        $this->get('/admin/articles/'.$article->id.'/edit')->assertOk()
            ->assertSee('VnExpress – Thế giới')->assertSee('Thế giới');
        $this->put('/admin/articles/'.$article->id, [
            'title' => 'Edited catalog headline', 'description' => 'Edited excerpt',
            'source_id' => '', 'category_id' => '',
        ])->assertRedirect('/admin/articles')->assertSessionHasNoErrors();
        $this->get('/admin/articles/'.$article->id)->assertOk()
            ->assertSee('Edited catalog headline')->assertSee('VnExpress – Thế giới')->assertSee('Thế giới');
        $stored = DB::connection('mongodb')->getCollection('articles')->findOne(['_id' => $article->id]);
        $this->assertNull($stored['source_id']);
        $this->assertNull($stored['category_id']);
        $this->get('/articles/edited-catalog-headline')->assertNotFound();
    }

    public function test_catalog_edit_form_assigns_integer_relations_and_becomes_publicly_eligible(): void
    {
        $article = Article::firstOrFail();
        $category = Category::create(['name' => 'Assigned topic', 'slug' => 'assigned', 'is_active' => true]);
        $source = Source::create(['name' => 'Assigned source', 'url' => 'https://assigned.test', 'feed_url' => 'https://assigned.test/rss', 'category_id' => $category->id, 'is_active' => true]);
        $this->put('/admin/articles/'.$article->id, [
            'title' => $article->title, 'source_id' => (string) $source->id, 'category_id' => (string) $category->id,
        ])->assertRedirect('/admin/articles')->assertSessionHasNoErrors();

        $stored = DB::connection('mongodb')->getCollection('articles')->findOne(['_id' => $article->id]);
        $this->assertSame($source->id, $stored['source_id']);
        $this->assertSame($category->id, $stored['category_id']);
        foreach (['source='.$source->id, 'category=assigned'] as $filter) {
            $items = $this->get('/admin/articles?'.$filter)->assertOk()->viewData('articles');
            $this->assertSame([$article->id], $items->getCollection()->map->getKey()->all());
        }
        $response = $this->getJson('/api/articles?page=1')->assertOk();
        $this->assertStringContainsString($article->title, $response->json('html'));
    }

    public function test_admin_can_delete_catalog_article_and_bulk_delete_uuid_selections(): void
    {
        $article = Article::firstOrFail();
        $this->post('/admin/articles/bulk-destroy', ['ids' => [$article->id, ['invalid'], 'not-an-id']])
            ->assertRedirect()->assertSessionHas('success', 'Đã xóa 1 bài viết.');
        $this->get('/admin/articles/'.$article->id)->assertNotFound();
    }

    public function test_admin_can_destroy_one_catalog_article(): void
    {
        $article = Article::firstOrFail();
        $this->delete('/admin/articles/'.$article->id)->assertRedirect();
        $this->get('/admin/articles/'.$article->id)->assertNotFound();
    }

    public function test_public_routes_preserve_catalog_exclusion(): void
    {
        $article = Article::firstOrFail();
        // An unrelated SQL category exercises a real category page, not a 404.
        $category = Category::create(['name' => 'Other topic', 'slug' => 'other-topic', 'is_active' => true]);
        foreach (['/', '/articles', '/search?q=bay', '/categories/'.$category->slug] as $url) {
            $this->get($url)->assertOk()->assertDontSee($article->title);
        }
        $this->get('/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/articles')->assertOk()->assertJsonPath('html', '');
        $this->getJson('/api/live-search?q=bay')->assertOk()->assertExactJson([]);
    }

    public function test_bookmarks_and_shared_components_render_snapshot_names_without_category_link(): void
    {
        $article = Article::firstOrFail();
        Bookmark::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
        $this->get('/bookmarks')->assertOk()->assertSee('VnExpress – Thế giới')->assertSee('Thế giới')
            ->assertDontSee('href="'.route('categories.show', 'the-gioi').'"', false);
        foreach (['article-card', 'article-hero'] as $component) {
            $html = Blade::render('<x-'.$component.' :article="$article" />', compact('article'));
            $this->assertStringContainsString('VnExpress – Thế giới', $html);
            $this->assertStringContainsString('Thế giới', $html);
            $this->assertStringNotContainsString('href="'.route('categories.show', 'the-gioi').'"', $html);
        }
    }

    public function test_admin_filters_and_count_sorting_work_with_catalog_articles(): void
    {
        foreach (['q=bay', 'category=the-gioi', 'source=1', 'date_from=2026-11-01&date_to=2026-11-30', 'sort=boosts', 'sort=bookmarks'] as $query) {
            $this->get('/admin/articles?'.$query)->assertOk();
        }
        $article = Article::firstOrFail();
        Bookmark::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
        Article::create(['title' => 'Unranked', 'slug' => 'unranked', 'published_at' => now(), 'original_url' => 'https://example.com/unranked']);
        $this->get('/admin/articles?sort=bookmarks')->assertOk()->assertSeeInOrder([$article->title, 'Unranked']);
    }

    public function test_index_query_counts_are_bounded_for_all_sorts(): void
    {
        // Use real SQL relations so eager loading is exercised as well.
        Article::query()->delete();
        $category = Category::create(['name' => 'Counts', 'slug' => 'counts']);
        $source = Source::create(['name' => 'Counts', 'url' => 'https://counts.test', 'feed_url' => 'https://counts.test/rss', 'category_id' => $category->id]);
        // Keep the authenticated user's entitlement snapshot equally warm.
        app(BillingGateway::class)->primeEntitlements([auth()->user()]);
        $measurements = [];
        $hydrated = 0;
        Article::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        foreach ([5, 25] as $size) {
            foreach (range($size === 5 ? 1 : 6, $size) as $i) {
                $article = Article::create([
                    'title' => "Count $i", 'slug' => "count-$i", 'original_url' => "https://counts.test/$i",
                    'published_at' => now()->subMinutes($i), 'source_id' => $source->id, 'category_id' => $category->id,
                ]);
                Bookmark::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
                Boost::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
            }
            foreach (['published_at', 'boosts', 'bookmarks'] as $sort) {
                $sql = DB::connection();
                $mongo = DB::connection('mongodb');
                $sql->enableQueryLog();
                $mongo->enableQueryLog();
                $sql->flushQueryLog();
                $mongo->flushQueryLog();
                $hydrated = 0;
                try {
                    $response = $this->get('/admin/articles?sort='.$sort)->assertOk();
                    $measurements[$size][$sort] = [count($sql->getQueryLog()), count($mongo->getQueryLog())];
                    $this->assertSame(min($size, 20), $hydrated);
                    $this->assertSame($size, $response->viewData('articles')->total());
                    $this->assertSame([1], $response->viewData('articles')->pluck('bookmarks_count')->unique()->values()->all());
                } finally {
                    $sql->disableQueryLog();
                    $mongo->disableQueryLog();
                }
            }
        }
        foreach (['published_at', 'boosts', 'bookmarks'] as $sort) {
            $this->assertSame($measurements[5][$sort], $measurements[25][$sort], $sort);
            // Gate 9b: the per-request sanction check moved from SQL to MongoDB (same total, one read).
            $this->assertSame([6 + ($sort === 'published_at' ? 0 : 1), 3], $measurements[25][$sort], $sort);
        }
    }

    public function test_count_sort_ties_zeros_filters_and_pagination(): void
    {
        Article::query()->delete();
        $ids = [];
        foreach (range(1, 25) as $i) {
            $article = Article::create([
                'title' => "Rank $i", 'slug' => "rank-$i", 'original_url' => "https://rank.test/$i",
                'published_at' => now()->startOfDay()->subMinutes($i),
            ]);
            $ids[] = $article->id;
            // Older Articles with counts outrank newer zero-count Articles.
            if (($i >= 5 && $i <= 22) || $i === 25) {
                foreach ([Bookmark::class, Boost::class] as $model) {
                    $model::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
                    if ($i === 25) {
                        $model::create(['user_id' => 'second-user', 'article_id' => $article->id]);
                    }
                }
            }
        }
        $excluded = Article::create(['title' => 'Excluded', 'slug' => 'excluded', 'original_url' => 'https://rank.test/excluded']);
        foreach ([Bookmark::class, Boost::class] as $model) {
            foreach (['one', 'two'] as $user) {
                $model::create(['user_id' => $user, 'article_id' => $excluded->id]);
            }
        }
        $expected = array_merge([$ids[24]], array_slice($ids, 4, 18), array_slice($ids, 0, 4), array_slice($ids, 22, 2));
        foreach (['boosts', 'bookmarks'] as $sort) {
            foreach ([1, 2] as $page) {
                $response = $this->get("/admin/articles?sort=$sort&q=Rank&page=$page")->assertOk();
                $articles = $response->viewData('articles');
                $this->assertSame(25, $articles->total());
                $this->assertSame(array_slice($expected, ($page - 1) * 20, 20), $articles->getCollection()->map->getKey()->all());
                $this->assertStringContainsString('sort='.$sort, $articles->url(2));
                $this->assertStringContainsString('q=Rank', $articles->url(2));
            }
        }
    }

    public function test_count_ranking_merges_three_small_chunks(): void
    {
        $this->app->instance(ArticleController::class, new class extends ArticleController
        {
            protected int $countChunkSize = 2;
        });
        Article::query()->delete();
        $ids = [];
        // Published order forms chunks [0,1], [2,3], [4,5]. The global winner
        // is in the final chunk; ties and missing counts span chunk boundaries.
        foreach ([1, 0, 2, 1, 3, 0] as $i => $count) {
            $article = Article::create([
                'title' => "Chunk $i", 'slug' => "chunk-$i", 'original_url' => "https://chunk.test/$i",
                'published_at' => now()->startOfDay()->subMinutes($i),
            ]);
            $ids[] = $article->id;
            foreach ([Boost::class, Bookmark::class] as $model) {
                for ($n = 0; $n < $count; $n++) {
                    $model::create(['user_id' => "chunk-user-$n", 'article_id' => $article->id]);
                }
            }
        }
        $expected = [$ids[4], $ids[2], $ids[0], $ids[3], $ids[1], $ids[5]];
        $sql = DB::connection();
        foreach (['boosts', 'bookmarks'] as $sort) {
            $sql->enableQueryLog();
            $sql->flushQueryLog();
            try {
                $items = $this->get('/admin/articles?sort='.$sort)->assertOk()->viewData('articles');
                $this->assertSame($expected, $items->getCollection()->map->getKey()->all());
                $this->assertSame([3, 2, 1, 1, 0, 0], $items->pluck($sort.'_count')->all());
                $rankingQueries = collect($sql->getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'order by "n" desc'));
                $this->assertCount(3, $rankingQueries);
                foreach ($rankingQueries as $entry) {
                    $this->assertCount(2, $entry['bindings']);
                }
            } finally {
                $sql->disableQueryLog();
            }
        }
    }

    public function test_identical_counts_and_dates_have_stable_id_order_across_pages(): void
    {
        Article::query()->delete();
        $ids = [];
        // Reverse insertion order ensures natural Mongo order cannot satisfy id ASC.
        foreach (range(21, 1) as $i) {
            $id = sprintf('00000000-0000-7000-8000-%012d', $i);
            $article = new Article([
                'title' => "Stable $i", 'slug' => "stable-$i", 'original_url' => "https://stable.test/$i",
                'published_at' => '2026-11-10 12:00:00',
            ]);
            $article->forceFill(['_id' => $id])->save();
            $ids[] = $id;
            foreach ([Boost::class, Bookmark::class] as $model) {
                $model::create(['user_id' => auth()->id(), 'article_id' => $id]);
            }
        }
        $expected = array_reverse($ids);
        foreach (['published_at', 'boosts', 'bookmarks'] as $sort) {
            foreach ([1, 2, 3] as $page) {
                $response = $this->get("/admin/articles?sort=$sort&page=$page")->assertOk();
                $items = $response->viewData('articles');
                $this->assertSame(21, $items->total());
                $this->assertSame(array_slice($expected, ($page - 1) * 20, 20), $items->getCollection()->map->getKey()->all());
                if ($page === 3) {
                    $response->assertDontSee('Stable ');
                }
            }
        }
    }

    public function test_count_sort_respects_combined_sql_identity_and_mongo_filters(): void
    {
        Article::query()->delete();
        $category = Category::create(['name' => 'Filtered', 'slug' => 'filtered']);
        $other = Category::create(['name' => 'Other', 'slug' => 'other']);
        $source = Source::create(['name' => 'Filtered', 'url' => 'https://filtered.test', 'feed_url' => 'https://filtered.test/rss', 'category_id' => $category->id]);
        $base = ['title' => 'Needle', 'published_at' => '2026-11-10 12:00:00', 'category_id' => $category->id, 'source_id' => $source->id];
        $ids = [];
        foreach ([[], ['title' => 'Description match', 'description' => 'Needle'], ['title' => 'Wrong text'], ['category_id' => $other->id], ['source_id' => 999], ['published_at' => '2026-10-31'], ['published_at' => '2026-12-01']] as $i => $override) {
            $article = Article::create(array_merge($base, $override, ['slug' => "filtered-$i", 'original_url' => "https://filtered.test/$i"]));
            $ids[] = $article->id;
            foreach ([Bookmark::class, Boost::class] as $model) {
                $model::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
            }
        }
        $filter = "&q=Needle&category=filtered&source={$source->id}&date_from=2026-11-01&date_to=2026-11-30";
        foreach (['boosts', 'bookmarks'] as $sort) {
            $articles = $this->get('/admin/articles?sort='.$sort.$filter)->assertOk()->viewData('articles');
            $this->assertSame(2, $articles->total());
            $this->assertEqualsCanonicalizing(array_slice($ids, 0, 2), $articles->getCollection()->map->getKey()->all());
        }
    }

    public function test_counts_are_explicit_and_show_loads_all_three(): void
    {
        $article = Article::firstOrFail();
        Bookmark::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
        Boost::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
        ArticleUnlock::create(['user_id' => auth()->id(), 'article_id' => $article->id, 'unlocked_at' => now()]);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        foreach (['bookmarks_count', 'boosts_count', 'unlocks_count'] as $attribute) {
            $this->assertNull($article->$attribute);
        }
        $this->assertCount(0, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $loaded = $this->get('/admin/articles/'.$article->id)->assertOk()->viewData('article');
        foreach (['bookmarks_count', 'boosts_count', 'unlocks_count'] as $attribute) {
            $this->assertSame(1, $loaded->$attribute);
        }
    }

    public function test_sql_relation_names_and_category_links_still_take_precedence(): void
    {
        $category = Category::create(['name' => 'SQL topic', 'slug' => 'sql-topic', 'is_active' => true]);
        $source = Source::create(['name' => 'SQL publisher', 'url' => 'https://example.com', 'feed_url' => 'https://example.com/rss', 'category_id' => $category->id, 'is_active' => true]);
        $article = Article::create([
            'title' => 'Relational article', 'slug' => 'relational-article',
            'category_id' => $category->id, 'source_id' => $source->id,
            'original_url' => 'https://example.com/relational', 'published_at' => now(),
        ]);
        $article->forceFill(['source_snapshot' => ['name' => 'Old publisher'], 'category_snapshot' => ['name' => 'Old topic']])->save();
        Bookmark::create(['user_id' => auth()->id(), 'article_id' => $article->id]);
        $this->get('/bookmarks')->assertOk()->assertSee('SQL publisher')->assertSee('SQL topic')
            ->assertSee('href="'.route('categories.show', 'sql-topic').'"', false)
            ->assertDontSee('Old publisher')->assertDontSee('Old topic');
        $this->get('/admin/articles/'.$article->id)->assertOk()->assertSee('SQL publisher')->assertSee('SQL topic');
        $this->putJson('/admin/articles/'.$article->id, ['title' => 'Still relational', 'source_id' => null, 'category_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['source_id', 'category_id']);
    }

    public function test_missing_snapshots_have_neutral_labels_and_no_category_link(): void
    {
        $article = Article::create(['title' => 'Missing metadata', 'slug' => 'missing-metadata', 'original_url' => 'https://example.com/missing']);
        $this->get('/admin/articles/'.$article->id)->assertOk()->assertSee('—');
        foreach (['article-card', 'article-hero'] as $component) {
            $html = Blade::render('<x-'.$component.' :article="$article" />', compact('article'));
            $this->assertStringContainsString('—', $html);
            $this->assertStringNotContainsString('/categories/', $html);
        }
    }
}
