<?php

namespace Tests\Concerns;

use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Shared fixtures for the Moderation*Test acceptance suites (Gate 9a).
 * Users/articles/reports/sanctions live on the isolated Mongo test database (Gate 9b), comments on
 * SQLite :memory:, the moderation queue and audit stream on Redis DB15.
 */
trait ModerationFixtures
{
    use GuardsRedisTestDatabase;

    /** Gate 9b: the review queue/stream live on Redis; start every moderation test from an empty DB15. */
    protected function setUpModerationFixtures(): void
    {
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email_verified_at' => now()], $attributes));
    }

    protected function admin(): User
    {
        return $this->user(['role' => 'admin']);
    }

    protected function article(string $slug = 'bai-viet-kiem-duyet'): Article
    {
        $category = Category::firstOrCreate(['slug' => 'fixture'], ['name' => 'Fixture', 'is_active' => true]);
        $source = Source::firstOrCreate(['name' => 'Fixture'], [
            'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss',
            'category_id' => $category->id, 'is_active' => true,
        ]);

        return Article::create([
            'title' => 'Bài viết kiểm duyệt', 'slug' => $slug, 'original_url' => 'https://example.test/'.$slug,
            'category_id' => $category->id, 'source_id' => $source->id, 'published_at' => now(),
        ]);
    }

    protected function comment(User $author, Article $article, string $body = 'Bình luận cần xem xét', ?int $parentId = null): Comment
    {
        return Comment::create([
            'user_id' => (string) $author->id, 'article_id' => (string) $article->id,
            'body' => $body, 'parent_id' => $parentId,
        ]);
    }

    protected function sanctionFor(User $user, User $admin, string $type = 'temporary_ban', array $attributes = []): Sanction
    {
        return Sanction::create(array_merge([
            'user_id' => (string) $user->id, 'admin_id' => (string) $admin->id, 'type' => $type,
            'reason' => 'Vi phạm quy định', 'is_active' => true,
            'expires_at' => in_array($type, ['mute', 'temporary_ban'], true) ? now()->addDays(3) : null,
        ], $attributes));
    }

    protected function report(User $reporter, Comment $comment, array $attributes = []): Report
    {
        $this->actingAs($reporter)->post(route('reports.store', $comment), array_merge(['reason' => 'spam'], $attributes));

        return Report::where('reporter_id', (string) $reporter->id)->where('comment_id', $comment->id)->firstOrFail();
    }
}
