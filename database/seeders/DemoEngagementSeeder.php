<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoEngagementSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'user')->get();
        $categories = Category::pluck('id', 'slug');

        if ($users->isEmpty() || $categories->isEmpty()) {
            return;
        }

        $interestMap = [
            'user1@vietfeed.com' => ['thoi-su', 'cong-nghe', 'kinh-doanh'],
            'user2@vietfeed.com' => ['giai-tri', 'suc-khoe', 'giao-duc'],
            'user3@vietfeed.com' => ['the-thao', 'the-gioi', 'cong-nghe'],
        ];

        foreach ($users as $user) {
            $slugs = $interestMap[$user->email] ?? ['thoi-su', 'cong-nghe'];
            $categoryIds = collect($slugs)
                ->map(fn (string $slug) => $categories[$slug] ?? null)
                ->filter()
                ->values()
                ->all();

            $user->favoriteCategories()->syncWithoutDetaching($categoryIds);
        }

        $articles = Article::latest('published_at')->take(40)->get();

        // This seeder is intentionally safe to run before feeds:fetch.
        // If there are no RSS articles yet, user/category demo data is still seeded.
        if ($articles->isEmpty()) {
            return;
        }

        $commentBodies = [
            'Tin này khá đáng chú ý, mình sẽ theo dõi thêm.',
            'Cảm ơn VietFeed, tóm tắt nguồn tin rất tiện.',
            'Góc nhìn này hay, nhất là phần so sánh với tình hình hiện tại.',
            'Mình thấy chủ đề này nên được cập nhật thường xuyên hơn.',
            'Bài viết hữu ích, để mình lưu lại đọc sau.',
            'Thông tin khá mới, cần xem thêm các nguồn khác để đối chiếu.',
        ];

        foreach ($users as $userIndex => $user) {
            $personalArticles = $articles->slice($userIndex * 8, 12)->values();

            foreach ($personalArticles->take(8) as $article) {
                Bookmark::updateOrCreate([
                    'user_id' => $user->id,
                    'article_id' => $article->id,
                ]);
            }

            foreach ($personalArticles->take(5) as $article) {
                Boost::updateOrCreate([
                    'user_id' => $user->id,
                    'article_id' => $article->id,
                ]);
            }

            foreach ($personalArticles->take(4)->values() as $i => $article) {
                Comment::firstOrCreate([
                    'user_id' => $user->id,
                    'article_id' => $article->id,
                    'parent_id' => null,
                    'body' => $commentBodies[($userIndex + $i) % count($commentBodies)],
                ]);
            }

            foreach ($personalArticles->take(10) as $article) {
                ArticleUnlock::firstOrCreate([
                    'user_id' => $user->id,
                    'article_id' => $article->id,
                ], [
                    'session_id' => null,
                    'unlocked_at' => now()->subMinutes(rand(10, 500)),
                ]);
            }
        }

        $firstComment = Comment::whereNull('parent_id')->first();
        if ($firstComment) {
            Comment::firstOrCreate([
                'user_id' => $users->last()->id,
                'article_id' => $firstComment->article_id,
                'parent_id' => $firstComment->id,
                'body' => 'Mình đồng ý, nhưng vẫn cần xem diễn biến trong vài ngày tới.',
            ]);
        }
    }
}
