<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\ModerationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/** Gate 9a: rules 13-14 plus hidden-comment visibility (owner decision 1). */
class ModerationEnforcementTest extends TestCase
{
    use ModerationFixtures, RefreshDatabase;

    // Rule 13 (D5)
    public function test_deleting_a_comment_by_author_or_admin_keeps_reports_and_snapshots(): void
    {
        $article = $this->article();
        $author = $this->user();
        $adminUser = $this->admin();
        $byAuthor = $this->comment($author, $article, 'Tự xóa bình luận');
        $byAdmin = $this->comment($author, $article, 'Admin xóa bình luận');
        $r1 = $this->report($this->user(), $byAuthor);
        $r2 = $this->report($this->user(), $byAdmin);

        $this->actingAs($author)->delete(route('comments.destroy', $byAuthor))->assertSessionHas('success');
        $this->actingAs($adminUser)->delete(route('comments.destroy', $byAdmin))->assertSessionHas('success');
        $this->actingAs($adminUser)->delete(route('admin.comments.destroy', $this->comment($author, $article)))->assertSessionHas('success');

        $this->assertSame(2, Report::count());
        $this->assertSame('Tự xóa bình luận', $r1->fresh()->comment_body);
        $this->assertSame('Admin xóa bình luận', $r2->fresh()->comment_body);
        $this->actingAs($adminUser)->get(route('admin.reports.show', $r1))->assertOk()->assertSee('Bình luận đã bị xóa');
    }

    public function test_deleting_a_parent_comment_keeps_reports_on_its_replies(): void
    {
        $article = $this->article();
        $parent = $this->comment($this->user(), $article, 'Gốc');
        $reply = $this->comment($this->user(), $article, 'Trả lời bị báo cáo', $parent->id);
        $this->report($this->user(), $reply);

        $parent->delete();

        $this->assertNull(Comment::find($reply->id));
        $this->assertSame(1, Report::count());
    }

    // Rule 14
    public function test_mute_blocks_commenting_only(): void
    {
        $article = $this->article();
        $user = $this->user();
        $this->sanctionFor($user, $this->admin(), 'mute');
        $this->actingAs($user);

        $this->post(route('comments.store', $article->slug), ['body' => 'Vẫn muốn nói'])->assertSessionHas('error');
        $this->assertSame(0, Comment::count());
        $this->postJson(route('bookmarks.toggle'), ['article_id' => (string) $article->id])->assertOk()->assertJson(['action' => 'added']);
        $this->postJson(route('boosts.toggle'), ['article_id' => (string) $article->id])->assertOk()->assertJson(['action' => 'added']);
        $this->get('/articles/'.$article->slug)->assertOk();
    }

    public function test_temporary_ban_blocks_write_actions_but_not_reading_profile_or_appeal(): void
    {
        $article = $this->article();
        $other = $this->comment($this->user(), $article, 'Bình luận của người khác');
        $user = $this->user();
        $sanction = $this->sanctionFor($user, $this->admin(), 'temporary_ban', ['reason' => 'Lý do cấm tạm thời']);
        $this->actingAs($user);

        $this->post(route('comments.store', $article->slug), ['body' => 'Không được'])->assertSessionHas('error');
        $this->postJson(route('bookmarks.toggle'), ['article_id' => (string) $article->id])->assertForbidden();
        $this->postJson(route('boosts.toggle'), ['article_id' => (string) $article->id])->assertForbidden();
        $this->post(route('reports.store', $other), ['reason' => 'spam'])->assertSessionHas('error');
        $this->assertSame(0, Bookmark::count() + Boost::count() + Report::count());
        $this->assertSame(1, Comment::count());

        $this->get('/')->assertOk();
        $this->get('/articles/'.$article->slug)->assertOk()->assertSee('Bình luận của người khác');
        $this->get(route('profile.edit'))->assertOk()->assertSee('Lý do cấm tạm thời');
        $this->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Tôi muốn kháng cáo quyết định'])->assertSessionHas('success');
    }

    public function test_permanent_ban_redirects_to_banned_page_except_logout_and_appeal(): void
    {
        $article = $this->article();
        $user = $this->user();
        $sanction = $this->sanctionFor($user, $this->admin(), 'permanent_ban', ['reason' => 'Lý do cấm vĩnh viễn']);
        $this->actingAs($user);

        $this->get('/')->assertRedirect(route('sanctions.banned'));
        $this->get('/articles/'.$article->slug)->assertRedirect(route('sanctions.banned'));
        $this->get(route('profile.edit'))->assertRedirect(route('sanctions.banned'));
        $this->get(route('sanctions.banned'))->assertOk()->assertSee('Lý do cấm vĩnh viễn')->assertSee(route('sanctions.appeal', $sanction), false);

        $this->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Xin xem xét lại án cấm'])->assertSessionHas('success');
        $this->assertSame('pending', $sanction->fresh()->appeal_status);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_expired_sanctions_stop_applying_without_any_job(): void
    {
        $article = $this->article();
        $user = $this->user();
        $this->sanctionFor($user, $this->admin(), 'temporary_ban', ['expires_at' => now()->addHour()]);
        $this->sanctionFor($user, $this->admin(), 'mute', ['expires_at' => now()->addHour()]);
        $this->assertTrue($user->isBanned());

        $this->travel(2)->hours();

        $user = $user->fresh();
        $this->assertFalse($user->isBanned());
        $this->assertFalse($user->isMuted());
        $this->actingAs($user)->post(route('comments.store', $article->slug), ['body' => 'Đã hết hạn cấm'])->assertSessionHas('success');
        $this->assertSame(1, Comment::count());
    }

    public function test_warning_does_not_restrict_the_user(): void
    {
        $user = $this->user();
        $this->sanctionFor($user, $this->admin(), 'warning');

        $this->actingAs($user)->get('/')->assertOk();
    }

    // Owner decision 1: hidden comments
    public function test_hidden_comment_is_invisible_except_to_author_and_admins(): void
    {
        $article = $this->article();
        $author = $this->user(['name' => 'Tác Giả Bị Ẩn']);
        $comment = $this->comment($author, $article, 'Nội dung đã bị ẩn kín');
        $comment->forceFill(['is_hidden' => true])->save();
        $visible = $this->comment($this->user(), $article, 'Nội dung hiển thị');
        $url = '/articles/'.$article->slug;

        $this->get($url)->assertOk()->assertDontSee('Nội dung đã bị ẩn kín')->assertDontSee('Tác Giả Bị Ẩn')->assertSee('Nội dung hiển thị');
        $this->actingAs($this->user())->get($url)->assertOk()->assertDontSee('Nội dung đã bị ẩn kín');
        $this->actingAs($author)->get($url)->assertOk()->assertSee('Nội dung đã bị ẩn kín')->assertSee('Bình luận đang bị ẩn');
        $this->actingAs($this->admin())->get($url)->assertOk()->assertSee('Nội dung đã bị ẩn kín')->assertSee('Bình luận đang bị ẩn');
    }

    public function test_comment_hiding_only_through_moderation_path(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $comment->update(['is_hidden' => true]);

        $this->assertFalse((bool) $comment->fresh()->is_hidden);
    }

    public function test_demo_seeder_builds_a_reportable_case_and_is_idempotent(): void
    {
        $this->seed(ModerationDemoSeeder::class);
        $this->seed(ModerationDemoSeeder::class);

        $admin = User::where('email', ModerationDemoSeeder::ADMIN)->firstOrFail();
        $this->assertSame(2, Report::count());
        $this->assertSame(1, Comment::count());
        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('thuốc giảm cân');
        $this->get('/articles/'.ModerationDemoSeeder::ARTICLE_SLUG)->assertOk()->assertSee('Người bình luận demo');
    }

    public function test_repair_migration_is_rerunnable_and_cleans_legacy_reviewed_rows(): void
    {
        $migration = require base_path('database/migrations/2026_11_17_100001_moderation_report_repair.php');
        // Gate 9.5: this migration backfills from the legacy SQL comments table (Comment is on MongoDB now).
        $author = $this->user();
        $comment = (object) ['id' => DB::table('comments')->insertGetId(['user_id' => (string) $author->id, 'article_id' => (string) $this->article()->id,
            'body' => 'Nội dung cũ trước khi có ảnh chụp', 'created_at' => now(), 'updated_at' => now()])];

        $migration->down();
        $this->assertTrue(Schema::hasColumn('reports', 'reviewed_at'));
        $this->assertFalse(Schema::hasColumn('reports', 'comment_body'));
        DB::table('reports')->insert([
            ['reporter_id' => 'r1', 'comment_id' => $comment->id, 'reason' => 'spam', 'status' => 'reviewed', 'admin_id' => 'a1', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['reporter_id' => 'r2', 'comment_id' => $comment->id, 'reason' => 'spam', 'status' => 'actioned', 'admin_id' => 'a1', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['reporter_id' => 'r3', 'comment_id' => 9999, 'reason' => 'spam', 'status' => 'pending', 'admin_id' => null, 'reviewed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();
        $migration->up(); // idempotent without a down() in between

        $schema = Schema::class;
        foreach (['decided_at', 'comment_body', 'comment_user_id', 'comment_article_id', 'decision_note', 'sanction_id'] as $column) {
            $this->assertTrue($schema::hasColumn('reports', $column), $column);
        }
        $this->assertFalse($schema::hasColumn('reports', 'reviewed_at'));
        $this->assertTrue($schema::hasColumn('comments', 'hidden_by_sanction_id'));
        $this->assertTrue($schema::hasColumn('sanctions', 'revoked_at'));

        // down() refuses while evidence exists and drops nothing.
        try {
            $migration->down();
            $this->fail('down() must refuse when moderation evidence exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
        }
        $this->assertTrue($schema::hasColumn('reports', 'comment_body'));
        $this->assertSame(3, DB::table('reports')->count()); // Gate 9b: legacy SQL table (Report is on MongoDB)

        $reviewed = DB::table('reports')->where('reporter_id', 'r1')->sole();
        $this->assertSame('pending', $reviewed->status);
        $this->assertNull($reviewed->decided_at);
        $this->assertNull($reviewed->admin_id);
        $this->assertSame('Nội dung cũ trước khi có ảnh chụp', $reviewed->comment_body);
        $actioned = DB::table('reports')->where('reporter_id', 'r2')->sole();
        $this->assertSame('actioned', $actioned->status);
        $this->assertNotNull($actioned->decided_at);
        $this->assertNull(DB::table('reports')->where('reporter_id', 'r3')->sole()->comment_body);

        // With the evidence cleared the full cycle works again.
        DB::table('reports')->update(['comment_body' => null, 'comment_user_id' => null, 'comment_article_id' => null]);
        $migration->down();
        $migration->up();
        $migration->up();
        $this->assertSame('Nội dung cũ trước khi có ảnh chụp', DB::table('reports')->where('reporter_id', 'r1')->sole()->comment_body);

        DB::table('comments')->where('id', $comment->id)->delete();
        $this->assertSame(3, DB::table('reports')->count()); // Gate 9b: legacy SQL table (Report is on MongoDB)
    }

    // R1: per-viewer sanction checks are computed once per request
    public function test_article_page_sanction_queries_do_not_grow_with_comment_count(): void
    {
        $article = $this->article();
        $viewer = $this->user();
        $count = function () use ($viewer, $article) {
            $n = 0;
            // Gate 9b: sanctions live on MongoDB; its command subscriber feeds the same QueryExecuted listener.
            DB::connection('mongodb')->enableQueryLog();
            \DB::listen(function ($q) use (&$n) {
                if (str_contains($q->sql, 'sanctions')) {
                    $n++;
                }
            });
            $this->actingAs($viewer)->get('/articles/'.$article->slug)->assertOk();

            return $n;
        };
        $author = $this->user();
        $parent = $this->comment($author, $article, 'Gốc 0');
        $this->comment($author, $article, 'Trả lời 0', $parent->id);
        $few = $count();
        $this->assertSame(1, $few, 'one sanctions read per request (Gate 9b: proves the Mongo counter is live)');
        for ($i = 1; $i < 6; $i++) {
            $p = $this->comment($author, $article, "Gốc $i");
            $this->comment($author, $article, "Trả lời $i", $p->id);
        }
        $this->assertSame($few, $count());
    }

    // R2
    public function test_restricted_or_hidden_comment_cannot_be_edited_by_author(): void
    {
        $article = $this->article();
        $adminUser = $this->admin();
        foreach (['mute', 'temporary_ban'] as $type) {
            $author = $this->user();
            $comment = $this->comment($author, $article, 'Gốc');
            $s = $this->sanctionFor($author, $adminUser, $type);
            $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'Sửa'])->assertSessionHas('error');
            $this->assertSame('Gốc', $comment->fresh()->body);
            $s->update(['is_active' => false]);
        }
        $author = $this->user();
        $this->sanctionFor($author, $adminUser, 'permanent_ban');
        $comment = $this->comment($author, $article, 'Gốc');
        $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'Sửa'])->assertRedirect(route('sanctions.banned'));
        $this->assertSame('Gốc', $comment->fresh()->body);

        $free = $this->user();
        $hidden = $this->comment($free, $article, 'Đã ẩn');
        $hidden->forceFill(['is_hidden' => true])->save();
        $this->actingAs($free)->put(route('comments.update', $hidden), ['body' => 'Sửa'])->assertSessionHas('error');
        $this->assertSame('Đã ẩn', $hidden->fresh()->body);
        $ok = $this->comment($free, $article, 'Bình thường');
        $this->actingAs($free)->put(route('comments.update', $ok), ['body' => 'Đã sửa'])->assertSessionHas('success');
        $this->assertSame('Đã sửa', $ok->fresh()->body);
    }

    // R12
    public function test_hidden_notice_differs_for_author_and_admin(): void
    {
        $article = $this->article();
        $author = $this->user();
        $this->comment($author, $article)->forceFill(['is_hidden' => true])->save();

        $this->actingAs($author)->get('/articles/'.$article->slug)->assertSee('Bình luận của bạn đã bị ẩn')->assertDontSee('đang bị ẩn khỏi người dùng khác');
        $this->actingAs($this->admin())->get('/articles/'.$article->slug)->assertSee('đang bị ẩn khỏi người dùng khác')->assertDontSee('Bình luận của bạn đã bị ẩn');
    }
}
