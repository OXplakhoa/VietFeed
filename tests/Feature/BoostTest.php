<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BoostTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_toggle_article_boost(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $article = $this->article('boost-me');

        $this->actingAs($user)
            ->postJson(route('boosts.toggle'), ['article_id' => $article->id])
            ->assertOk()
            ->assertJson(['action' => 'added', 'count' => 1]);

        $this->assertDatabaseHas('boosts', [
            'user_id' => $user->id,
            'article_id' => $article->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('boosts.toggle'), ['article_id' => $article->id])
            ->assertOk()
            ->assertJson(['action' => 'removed', 'count' => 0]);

        $this->assertDatabaseMissing('boosts', [
            'user_id' => $user->id,
            'article_id' => $article->id,
        ]);
    }

    public function test_guests_cannot_boost(): void
    {
        $article = $this->article('guest-boost');

        $this->postJson(route('boosts.toggle'), ['article_id' => $article->id])
            ->assertUnauthorized();
    }

    public function test_homepage_ranks_trending_by_recent_boost_count_then_publish_time(): void
    {
        $freshLow = $this->article('fresh-low', now()->subHour());
        $freshHigh = $this->article('fresh-high', now()->subHours(2));
        $oldHigh = $this->article('old-high', now()->subDays(5));

        $this->boost($freshLow, 1);
        $this->boost($freshHigh, 3);
        $this->boost($oldHigh, 5);

        $response = $this->get(route('home'))->assertOk();

        $trending = $response->viewData('trending');

        $this->assertSame($freshHigh->id, $trending->first()->id);
        $this->assertTrue($trending->contains('id', $freshLow->id));
        $this->assertTrue($trending->contains('id', $oldHigh->id), 'Old articles are allowed only as backfill.');
        $this->assertGreaterThan(
            $trending->search(fn (Article $article) => $article->id === $freshLow->id),
            $trending->search(fn (Article $article) => $article->id === $oldHigh->id)
        );
    }

    private function article(string $slug, ?Carbon $publishedAt = null): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'news'],
            ['name' => 'Thời sự']
        );

        $source = Source::query()->firstOrCreate(
            ['feed_url' => 'https://example.com/rss'],
            [
                'name' => 'VietFeed Test',
                'url' => 'https://example.com',
                'category_id' => $category->id,
                'is_active' => true,
            ]
        );

        return Article::create([
            'source_id' => $source->id,
            'category_id' => $category->id,
            'title' => Str::headline($slug),
            'slug' => $slug,
            'description' => 'Nội dung kiểm thử Boost.',
            'image_url' => 'https://example.com/image.jpg',
            'original_url' => "https://example.com/articles/{$slug}",
            'published_at' => $publishedAt ?? now(),
        ]);
    }

    private function boost(Article $article, int $count): void
    {
        User::factory()->count($count)->create()->each(function (User $user) use ($article) {
            Boost::create([
                'user_id' => $user->id,
                'article_id' => $article->id,
            ]);
        });
    }
}
