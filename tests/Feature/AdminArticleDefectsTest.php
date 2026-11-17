<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\Rss\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminArticleDefectsTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        // Ordinary RSS-style Article with real MongoDB relations; no catalog ingest.
        $category = Category::create(['name' => 'Ordinary topic', 'slug' => 'ordinary', 'is_active' => true]);
        $source = Source::create(['name' => 'Ordinary source', 'url' => 'https://ordinary.test', 'feed_url' => 'https://ordinary.test/rss', 'category_id' => $category->id, 'is_active' => true]);
        $this->article = Article::create([
            'title' => 'Ordinary RSS headline', 'slug' => 'ordinary-rss', 'original_url' => 'https://ordinary.test/article',
            'source_id' => $source->id, 'category_id' => $category->id, 'published_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_index_accepts_an_ordinary_article(): void
    {
        $this->get('/admin/articles')->assertOk()->assertSee($this->article->title);
    }

    public function test_rss_ingestion_stores_uuid_relation_ids(): void
    {
        $source = $this->article->source;
        Http::preventStrayRequests();
        Http::fake([$source->feed_url => Http::response(
            '<rss version="2.0"><channel><item><title>Ingested type probe</title><link>https://ordinary.test/type-probe</link><description>Type probe</description></item></channel></rss>'
        )]);
        app(FeedIngestionService::class)->fetchSource($source);
        $stored = DB::connection('mongodb')->getCollection('articles')->findOne(['original_url' => 'https://ordinary.test/type-probe']);
        $this->assertSame($source->id, $stored['source_id']);
        $this->assertSame($source->category_id, $stored['category_id']);
        $this->assertTrue(Str::isUuid($stored['source_id'], 7));
        $this->assertTrue(Str::isUuid($stored['category_id'], 7));
    }

    public function test_edit_form_persists_uuid_relation_ids_and_preserves_listing_eligibility(): void
    {
        $article = $this->article;
        $category = Category::create(['name' => 'Reassigned topic', 'slug' => 'reassigned', 'is_active' => true]);
        $source = Source::create(['name' => 'Reassigned source', 'url' => 'https://reassigned.test', 'feed_url' => 'https://reassigned.test/rss', 'category_id' => $category->id, 'is_active' => true]);
        $this->put('/admin/articles/'.$article->id, [
            'title' => $article->title,
            'source_id' => (string) $source->id,
            'category_id' => (string) $category->id,
        ])->assertRedirect('/admin/articles')->assertSessionHasNoErrors();

        $stored = DB::connection('mongodb')->getCollection('articles')->findOne(['_id' => $article->id]);
        $this->assertSame($source->id, $stored['source_id']);
        $this->assertSame($category->id, $stored['category_id']);
        foreach (['source='.$source->id, 'category=reassigned'] as $filter) {
            $items = $this->get('/admin/articles?'.$filter)->assertOk()->viewData('articles');
            $this->assertSame([$article->id], $items->getCollection()->map->getKey()->all());
        }
        $response = $this->getJson('/api/articles?page=1')->assertOk()->assertJsonFragment(['hasMore' => false]);
        $this->assertStringContainsString($article->title, $response->json('html'));
    }

    public static function invalidFilters(): array
    {
        return [
            'from garbage' => ['date_from', 'garbage'],
            'from impossible' => ['date_from', '2026-02-31'],
            'from array' => ['date_from', ['2026-11-01']],
            'to garbage' => ['date_to', 'garbage'],
            'to impossible' => ['date_to', '2026-02-31'],
            'to array' => ['date_to', ['2026-11-01']],
            'source garbage' => ['source', 'garbage'],
            'source fractional' => ['source', '1.5'],
            'source array' => ['source', ['1']],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_redirect_to_clean_index_with_validation_errors(string $field, mixed $value): void
    {
        $this->from('/admin/articles')->get('/admin/articles?'.http_build_query([$field => $value]))
            ->assertRedirect('/admin/articles')->assertSessionHasErrors($field);
    }

    public static function invalidFilterOrigins(): array
    {
        return [
            'no previous URL' => ['none'],
            'previous URL is the invalid URL' => ['same'],
            'external Referer' => ['external'],
        ];
    }

    #[DataProvider('invalidFilterOrigins')]
    public function test_invalid_filters_always_redirect_once_to_clean_index_and_display_errors(string $origin): void
    {
        $indexUrl = route('admin.articles.index');
        $invalidUrl = $indexUrl.'?date_from=garbage&date_to=2026-02-31&source=garbage';
        if ($origin === 'same') {
            $this->withSession(['_previous' => ['url' => $invalidUrl]]);
        } elseif ($origin === 'external') {
            $this->withHeader('Referer', 'https://another-site.test/admin/articles?source=garbage');
        } else {
            $this->assertNull(session()->previousUrl());
        }

        $response = $this->get($invalidUrl);
        $this->assertSame($indexUrl, $response->headers->get('Location'));
        $response->assertRedirect($indexUrl)
            ->assertSessionHasErrors(['date_from', 'date_to', 'source']);
        $message = session('errors')->first();
        $this->assertNotEmpty($message);
        // Carry the browser's session cookie through the redirect (JSON sessions).
        $this->withCookie(session()->getName(), session()->getId());
        $this->get($response->headers->get('Location'))
            ->assertOk()->assertSeeText($message)->assertSee($this->article->title);
    }

    public function test_empty_nullable_filters_are_accepted(): void
    {
        $this->get('/admin/articles?date_from=&date_to=&source=')->assertOk()->assertSee($this->article->title);
    }

    public function test_bulk_delete_accepts_an_ordinary_uuid(): void
    {
        $this->post('/admin/articles/bulk-destroy', ['ids' => [$this->article->id]])
            ->assertRedirect()->assertSessionHas('success', 'Đã xóa 1 bài viết.');
        $this->assertNull(Article::find($this->article->id));
    }

    public function test_bookmark_resolves_an_ordinary_article(): void
    {
        $bookmark = Bookmark::create(['user_id' => auth()->id(), 'article_id' => $this->article->id]);
        $this->assertSame($this->article->id, $bookmark->fresh()->article?->id);
        $this->get('/bookmarks')->assertOk()->assertSee($this->article->title);
    }
}
