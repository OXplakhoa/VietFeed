<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_page_is_public(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('VietFeed Pro')
            ->assertSee('49.000₫');
    }

    public function test_unverified_user_cannot_start_checkout(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'))
            ->assertRedirect();
    }

    public function test_active_pro_subscription_makes_reading_pass_unlimited(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['stripe_id' => 'cus_test_123'])->save();

        DB::table('subscriptions')->insert([
            'user_id' => $user->id,
            'type' => 'pro',
            'stripe_id' => 'sub_test_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_123',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = $this->article();
        $result = app(ReadingPassService::class)->accessOrLock($article, $user->fresh());

        $this->assertTrue($result['canAccess']);
        $this->assertSame('pro', $result['allowance']['tier']);
        $this->assertSame(-1, $result['allowance']['limit']);
    }

    private function article(): Article
    {
        $category = Category::create(['name' => 'Thời sự', 'slug' => 'thoi-su']);
        $source = Source::create([
            'name' => 'Nguồn test',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/rss',
            'category_id' => $category->id,
            'is_active' => true,
            'prestige' => 1,
        ]);

        return Article::create([
            'source_id' => $source->id,
            'category_id' => $category->id,
            'title' => 'Bài test Pro',
            'slug' => 'bai-test-pro',
            'description' => 'Nội dung test',
            'original_url' => 'https://example.com/bai-test-pro',
            'published_at' => now(),
        ]);
    }
}
