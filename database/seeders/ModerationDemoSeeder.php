<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for the moderation walkthrough (docs/demo/moderation-demo.md).
 * Not part of DatabaseSeeder: run it explicitly, and never against data you care about.
 *   php artisan db:seed --class=ModerationDemoSeeder
 * Idempotent; every account uses the password "password".
 */
class ModerationDemoSeeder extends Seeder
{
    public const ADMIN = 'mod-admin@vietfeed.test';

    public const AUTHOR = 'mod-author@vietfeed.test';

    public const REPORTER = 'mod-reporter@vietfeed.test';

    public const REPORTER_TWO = 'mod-reporter2@vietfeed.test';

    public const ARTICLE_SLUG = 'bai-viet-demo-kiem-duyet';

    public function run(): void
    {
        $this->account(self::ADMIN, 'Quản trị kiểm duyệt', 'admin');
        $author = $this->account(self::AUTHOR, 'Người bình luận demo');
        $reporter = $this->account(self::REPORTER, 'Người báo cáo một');
        $reporterTwo = $this->account(self::REPORTER_TWO, 'Người báo cáo hai');

        $category = Category::firstOrCreate(['slug' => 'kiem-duyet-demo'], ['name' => 'Kiểm duyệt demo', 'is_active' => true]);
        $source = Source::firstOrCreate(['name' => 'Nguồn demo kiểm duyệt'], [
            'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss',
            'category_id' => $category->id, 'is_active' => true,
        ]);
        $article = Article::firstOrCreate(['slug' => self::ARTICLE_SLUG], [
            'title' => 'Bài viết demo cho kiểm duyệt bình luận',
            'description' => 'Bài viết dùng cho hướng dẫn kiểm duyệt.',
            'original_url' => 'https://example.test/'.self::ARTICLE_SLUG,
            'category_id' => $category->id, 'source_id' => $source->id, 'published_at' => now(),
        ]);

        $comment = Comment::firstOrCreate(
            ['article_id' => (string) $article->id, 'user_id' => (string) $author->id],
            ['body' => 'Mua ngay thuốc giảm cân tại đây, giá rẻ bất ngờ!'],
        );

        foreach ([[$reporter, 'spam', 'Quảng cáo trá hình'], [$reporterTwo, 'misinformation', null]] as [$who, $reason, $description]) {
            Report::firstOrCreate(['reporter_id' => (string) $who->id, 'comment_id' => $comment->id], [
                'reason' => $reason, 'description' => $description,
                'comment_body' => $comment->body, 'comment_user_id' => (string) $author->id,
                'comment_article_id' => (string) $article->id,
            ]);
        }

        unset($admin);
    }

    private function account(string $email, string $name, string $role = 'user'): User
    {
        return User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make('password'),
            'role' => $role, 'email_verified_at' => now(),
        ]);
    }
}
