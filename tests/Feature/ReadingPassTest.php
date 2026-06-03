<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingPassTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_five_daily_unlocks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 10:00:00', 'Asia/Ho_Chi_Minh'));
        Session::setId('guest-pass-session');

        $service = app(ReadingPassService::class);
        $articles = collect(range(1, 6))->map(fn (int $i) => $this->article("guest-{$i}"));

        $articles->take(5)->each(function (Article $article) use ($service) {
            $this->assertTrue($service->accessOrLock($article, null)['canAccess']);
        });

        $this->assertFalse($service->accessOrLock($articles->last(), null)['canAccess']);
        $this->assertSame(5, ArticleUnlock::whereNull('user_id')->count());
    }

    public function test_reopening_same_article_does_not_consume_another_unlock(): void
    {
        Session::setId('same-article-session');
        $article = $this->article('same-article');
        $service = app(ReadingPassService::class);

        $this->assertTrue($service->accessOrLock($article, null)['canAccess']);
        $this->assertTrue($service->accessOrLock($article, null)['canAccess']);

        $this->assertSame(1, ArticleUnlock::count());
    }

    public function test_locked_article_hides_original_link_and_comments(): void
    {
        $user = User::factory()->unverified()->create();
        $articles = collect(range(1, 9))->map(fn (int $i) => $this->article("locked-link-{$i}"));

        $articles->take(8)->each(fn (Article $article) => $this->actingAs($user)->get(route('articles.show', $article->slug))->assertOk());

        $this->actingAs($user)
            ->get(route('articles.show', $articles->last()->slug))
            ->assertOk()
            ->assertSee('Gói đọc VietFeed đã hết lượt')
            ->assertDontSee($articles->last()->original_url, false)
            ->assertSee('Tạo tài khoản, xác minh email hoặc nâng cấp Pro')
            ->assertDontSee('Đăng bình luận');
    }

    public function test_unverified_user_gets_eight_unlocks_per_vietnam_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00', 'Asia/Ho_Chi_Minh'));
        $user = User::factory()->unverified()->create();
        $articles = collect(range(1, 9))->map(fn (int $i) => $this->article("unverified-{$i}"));

        $articles->take(8)->each(fn (Article $article) => $this->actingAs($user)->get(route('articles.show', $article->slug))->assertOk());

        $this->actingAs($user)
            ->get(route('articles.show', $articles->last()->slug))
            ->assertOk()
            ->assertSee('Gói đọc VietFeed đã hết lượt');

        $this->assertSame(8, ArticleUnlock::where('user_id', $user->id)->count());
    }

    public function test_verified_user_gets_fifteen_unlocks_per_rolling_five_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00', 'Asia/Ho_Chi_Minh'));
        $user = User::factory()->create();
        $articles = collect(range(1, 17))->map(fn (int $i) => $this->article("verified-{$i}"));

        $articles->take(15)->each(fn (Article $article) => $this->actingAs($user)->get(route('articles.show', $article->slug))->assertOk());

        $this->actingAs($user)
            ->get(route('articles.show', $articles[15]->slug))
            ->assertOk()
            ->assertSee('Gói đọc VietFeed đã hết lượt');

        Carbon::setTestNow(Carbon::parse('2026-06-03 17:01:00', 'Asia/Ho_Chi_Minh'));

        $this->actingAs($user)
            ->get(route('articles.show', $articles[16]->slug))
            ->assertOk()
            ->assertDontSee('Gói đọc VietFeed đã hết lượt');
    }

    public function test_admin_has_unlimited_untracked_reading_pass_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $articles = collect(range(1, 20))->map(fn (int $i) => $this->article("admin-{$i}"));

        $articles->each(fn (Article $article) => $this->actingAs($admin)
            ->get(route('articles.show', $article->slug))
            ->assertOk()
            ->assertDontSee('Gói đọc VietFeed đã hết lượt'));

        $this->assertSame(0, ArticleUnlock::where('user_id', $admin->id)->count());
    }

    public function test_guest_unlocks_transfer_to_user_after_login_in_same_browser_session(): void
    {
        $article = $this->article('transfer-me');
        $user = User::factory()->create(['password' => bcrypt('password')]);

        ArticleUnlock::create([
            'article_id' => $article->id,
            'session_id' => 'transfer-session',
            'unlocked_at' => now(),
        ]);

        app(ReadingPassService::class)->transferGuestUnlocks($user, 'transfer-session');

        $this->assertDatabaseHas('article_unlocks', [
            'article_id' => $article->id,
            'user_id' => $user->id,
            'session_id' => null,
        ]);
    }

    private function article(string $slug): Article
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
            'description' => 'Mô tả ngắn hiển thị trước khi mở bài.',
            'image_url' => 'https://example.com/image.jpg',
            'original_url' => "https://example.com/articles/{$slug}",
            'published_at' => now(),
        ]);
    }
}
