<?php

namespace Tests\Concerns;

use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\Source;
use App\Models\User;
use App\Moderation\ModerationCases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Shared fixtures for the Moderation*Test acceptance suites (Gate 9a).
 * Users/articles/reports/sanctions (Gate 9b) and comments (Gate 9.5) live on the isolated Mongo test
 * database, the moderation queue and audit stream on Redis DB15.
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

    protected function comment(User $author, Article $article, string $body = 'Bình luận cần xem xét', ?string $parentId = null): Comment
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

    /**
     * Gate 9.5: a pending report and its case exactly as 9b runtime stored them, keyed by a legacy SQL
     * comment id (an int, or a numeric string from a faulty earlier run). Not creatable through the app.
     */
    protected function legacyKeyedReport(string $reporterId, int|string $commentId, array $attributes = []): Report
    {
        $report = Report::withoutEvents(fn () => Report::create($attributes + ['reporter_id' => $reporterId, 'comment_id' => 'fixture', 'reason' => 'spam']));
        DB::connection('mongodb')->getMongoDB()->selectCollection('reports')->updateOne(['_id' => (string) $report->id], ['$set' => ['comment_id' => $commentId]]);
        $cases = app(ModerationCases::class);
        $caseId = $cases->ensureFor(Report::find($report->id));
        Report::where('_id', $report->id)->update(['case_id' => $caseId]);
        $cases->register(Report::find($report->id));

        return Report::find($report->id);
    }

    protected function report(User $reporter, Comment $comment, array $attributes = []): Report
    {
        $this->actingAs($reporter)->post(route('reports.store', $comment), array_merge(['reason' => 'spam'], $attributes));

        return Report::where('reporter_id', (string) $reporter->id)->where('comment_id', $comment->id)->firstOrFail();
    }
}
