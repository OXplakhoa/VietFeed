<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Source;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Gate 3 T3: Article (+ minimal Story) CRUD on Mongo with Laravel UUIDv7 IDs,
// original_url dedup, slug route resolution, and Dashboard numbers parity.
class ArticleMongoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_with_uuid7_ids(): void
    {
        $article = Article::create([
            'title' => 'CRUD target',
            'slug' => 'crud-target',
            'original_url' => 'https://ex.com/crud-target',
            'description' => 'body',
            'published_at' => now(),
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $article->getKey(),
        );

        $this->assertSame('CRUD target', Article::find($article->getKey())->title);

        $article->update(['title' => 'CRUD renamed']);
        $this->assertSame('CRUD renamed', $article->fresh()->title);

        $article->delete();
        $this->assertNull(Article::find($article->getKey()));
    }

    public function test_original_url_dedup_via_update_or_create(): void
    {
        Article::updateOrCreate(
            ['original_url' => 'https://ex.com/dedup'],
            ['title' => 'v1', 'slug' => 'dedup', 'description' => 'd', 'published_at' => now()],
        );
        Article::updateOrCreate(
            ['original_url' => 'https://ex.com/dedup'],
            ['title' => 'v2', 'slug' => 'dedup', 'description' => 'd', 'published_at' => now()],
        );

        $this->assertSame(1, Article::where('original_url', 'https://ex.com/dedup')->count());
        $this->assertSame('v2', Article::where('original_url', 'https://ex.com/dedup')->first()->title);
    }

    public function test_show_route_resolves_by_slug(): void
    {
        $category = Category::create(['name' => 'Show', 'slug' => 'show', 'is_active' => true]);
        $source = Source::create(['name' => 'Show S', 'url' => 'https://shows', 'feed_url' => 'https://shows/rss', 'category_id' => $category->id, 'is_active' => true]);
        Article::create([
            'title' => 'Routed', 'slug' => 'routed', 'original_url' => 'https://ex.com/routed',
            'description' => 'd', 'published_at' => now(),
            'category_id' => $category->id, 'source_id' => $source->id,
        ]);

        $this->get(route('articles.show', 'routed'))->assertOk();
    }

    public function test_story_doc_minimal(): void
    {
        $story = Story::create(['title' => 'S1', 'status' => 'draft']);

        $this->assertNotEmpty($story->getKey());
        $this->assertSame('draft', Story::find($story->getKey())->status);
    }

    public function test_load_more_second_page(): void
    {
        $category = Category::create(['name' => 'LM', 'slug' => 'lm', 'is_active' => true]);
        $source = Source::create(['name' => 'LM S', 'url' => 'https://lms', 'feed_url' => 'https://lms/rss', 'category_id' => $category->id, 'is_active' => true]);
        foreach (range(1, 13) as $i) {
            Article::create([
                'title' => "LM $i", 'slug' => "lm-$i", 'original_url' => "https://ex.com/lm-$i",
                'description' => 'd', 'published_at' => now(),
                'category_id' => $category->id, 'source_id' => $source->id,
            ]);
        }

        $response = $this->getJson(route('api.articles.load', ['page' => 2]));
        $response->assertOk();
        $response->assertJson(['hasMore' => false, 'nextPage' => 3]);
    }

    public function test_dashboard_numbers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $c1 = Category::create(['name' => 'C1', 'slug' => 'c1', 'is_active' => true]);
        $c2 = Category::create(['name' => 'C2', 'slug' => 'c2', 'is_active' => true]);
        $s1 = Source::create(['name' => 'S1', 'url' => 'https://s1', 'feed_url' => 'https://s1/rss', 'category_id' => $c1->id]);
        $s2 = Source::create(['name' => 'S2', 'url' => 'https://s2', 'feed_url' => 'https://s2/rss', 'category_id' => $c2->id]);

        $a1 = Article::create(['title' => 'A1', 'slug' => 'a1', 'original_url' => 'https://ex.com/a1', 'description' => 'd', 'published_at' => now(), 'category_id' => $c1->id, 'source_id' => $s1->id]);
        $a2 = Article::create(['title' => 'A2', 'slug' => 'a2', 'original_url' => 'https://ex.com/a2', 'description' => 'd', 'published_at' => now(), 'category_id' => $c1->id, 'source_id' => $s2->id]);
        $a3 = Article::create(['title' => 'A3', 'slug' => 'a3', 'original_url' => 'https://ex.com/a3', 'description' => 'd', 'published_at' => now(), 'category_id' => $c2->id, 'source_id' => $s1->id]);
        $old = Article::create(['title' => 'OLD', 'slug' => 'old', 'original_url' => 'https://ex.com/old', 'description' => 'd', 'published_at' => now()->subHours(100), 'category_id' => $c2->id, 'source_id' => $s1->id]);

        Bookmark::create(['user_id' => $u1->getKey(), 'article_id' => $a1->getKey()]);
        Bookmark::create(['user_id' => $u2->getKey(), 'article_id' => $a1->getKey()]);
        Bookmark::create(['user_id' => $u1->getKey(), 'article_id' => $a2->getKey()]);

        Boost::create(['user_id' => $u1->getKey(), 'article_id' => $a3->getKey()]);
        Boost::create(['user_id' => $u2->getKey(), 'article_id' => $a3->getKey()]);
        Boost::create(['user_id' => $u1->getKey(), 'article_id' => $a1->getKey()]);
        Boost::create(['user_id' => $u1->getKey(), 'article_id' => $old->getKey()]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();

        $perCategory = $response->viewData('perCategory')->pluck('articles_count', 'id');
        $this->assertSame(2, $perCategory[$c1->id]);
        $this->assertSame(2, $perCategory[$c2->id]);

        $mostBookmarked = $response->viewData('mostBookmarked');
        $this->assertSame([$a1->getKey(), $a2->getKey()], $mostBookmarked->map->getKey()->all());
        $this->assertSame([2, 1], $mostBookmarked->pluck('bookmarks_count')->all());

        $mostBoosted = $response->viewData('mostBoosted');
        $this->assertSame([$a3->getKey(), $a1->getKey()], $mostBoosted->map->getKey()->all());
        $this->assertSame([2, 1], $mostBoosted->pluck('boosts_count')->all());

        $perSource = $response->viewData('perSource')->pluck('articles_count', 'id');
        $this->assertSame(3, $perSource[$s1->id]);
        $this->assertSame(1, $perSource[$s2->id]);
    }
}
