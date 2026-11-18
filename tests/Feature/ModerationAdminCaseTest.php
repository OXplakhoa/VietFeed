<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Sanction;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/** Gate 9a, admin side: rules 5-9 (reports inbox, case page, statuses, sanction/dismiss from a case). */
class ModerationAdminCaseTest extends TestCase
{
    use ModerationFixtures;

    // Rule 5 (D2, D3)
    public function test_inbox_lists_cases_newest_first_grouped_by_comment_with_counts(): void
    {
        $article = $this->article();
        $author = $this->user(['name' => 'Tác Giả Bị Báo']);
        $old = $this->comment($author, $article, 'Bình luận cũ bị báo cáo');
        $new = $this->comment($author, $article, 'Bình luận mới bị báo cáo');
        $r1 = $this->user(['name' => 'Người Báo Một']);
        $r2 = $this->user(['name' => 'Người Báo Hai']);
        $r3 = $this->user(['name' => 'Người Báo Ba']);

        $this->report($r1, $old);
        $this->report($r2, $old);
        $this->travel(5)->minutes();
        $this->report($r3, $new);

        $response = $this->actingAs($this->admin())->get(route('admin.reports.index'));

        $response->assertOk()->assertSeeInOrder(['Bình luận mới bị báo cáo', 'Bình luận cũ bị báo cáo'])->assertSee('Tác Giả Bị Báo');
        $cases = $response->viewData('cases');
        $this->assertCount(2, $cases);
        $this->assertSame($new->id, (string) $cases[0]->comment_id);
        $this->assertSame(1, (int) $cases[0]->reports_count);
        $this->assertSame($old->id, (string) $cases[1]->comment_id);
        $this->assertSame(2, (int) $cases[1]->reports_count);
    }

    public function test_inbox_filters_by_status_and_reason(): void
    {
        $article = $this->article();
        $author = $this->user();
        $a = $this->comment($author, $article, 'Bình luận spam đang chờ');
        $b = $this->comment($author, $article, 'Bình luận quấy rối đã bác bỏ');
        $reporter = $this->user();
        $this->report($reporter, $a, ['reason' => 'spam']);
        $ra = $this->report($reporter, $b, ['reason' => 'harassment']);
        $ra->forceFill(['status' => 'dismissed'])->save();
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->get(route('admin.reports.index', ['status' => 'pending']))
            ->assertOk()->assertSee('Bình luận spam đang chờ')->assertDontSee('Bình luận quấy rối đã bác bỏ');
        $this->actingAs($adminUser)->get(route('admin.reports.index', ['status' => 'dismissed']))
            ->assertOk()->assertSee('Bình luận quấy rối đã bác bỏ')->assertDontSee('Bình luận spam đang chờ');
        $this->actingAs($adminUser)->get(route('admin.reports.index', ['reason' => 'harassment']))
            ->assertOk()->assertSee('Bình luận quấy rối đã bác bỏ')->assertDontSee('Bình luận spam đang chờ');
    }

    public function test_inbox_search_by_reporter_name_snapshot_text_and_description(): void
    {
        $article = $this->article();
        $author = $this->user();
        $a = $this->comment($author, $article, 'Quảng cáo thuốc giảm cân');
        $b = $this->comment($author, $article, 'Lời lẽ khiếm nhã');
        $this->report($this->user(['name' => 'Nguyễn Thám Tử']), $a);
        $this->report($this->user(['name' => 'Người Khác']), $b, ['description' => 'có dấu hiệu đe dọa nghiêm trọng']);
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->get(route('admin.reports.index', ['q' => 'Thám Tử']))
            ->assertOk()->assertSee('Quảng cáo thuốc giảm cân')->assertDontSee('Lời lẽ khiếm nhã');
        $this->actingAs($adminUser)->get(route('admin.reports.index', ['q' => 'thuốc giảm']))
            ->assertOk()->assertSee('Quảng cáo thuốc giảm cân')->assertDontSee('Lời lẽ khiếm nhã');
        $this->actingAs($adminUser)->get(route('admin.reports.index', ['q' => 'đe dọa']))
            ->assertOk()->assertSee('Lời lẽ khiếm nhã')->assertDontSee('Quảng cáo thuốc giảm cân');
        $this->actingAs($adminUser)->get(route('admin.reports.index', ['q' => '100% [x]_']))->assertOk();
    }

    public function test_inbox_pagination_keeps_filters(): void
    {
        $article = $this->article();
        $author = $this->user();
        $reporter = $this->user();
        for ($i = 0; $i < 21; $i++) {
            $this->report($reporter, $this->comment($author, $article, "Bình luận số $i"), ['reason' => 'spam']);
        }

        $response = $this->actingAs($this->admin())->get(route('admin.reports.index', ['status' => 'pending', 'reason' => 'spam', 'q' => 'Bình']));

        $response->assertOk();
        $this->assertCount(20, $response->viewData('cases'));
        $this->assertStringContainsString('status=pending', html_entity_decode($response->viewData('cases')->nextPageUrl()));
        $this->assertStringContainsString('reason=spam', html_entity_decode($response->viewData('cases')->nextPageUrl()));
        $this->assertStringContainsString('q=', $response->viewData('cases')->nextPageUrl());
    }

    public function test_inbox_does_not_run_per_row_queries(): void
    {
        $article = $this->article();
        $author = $this->user();
        $reporter = $this->user();
        for ($i = 0; $i < 6; $i++) {
            $this->report($reporter, $this->comment($author, $article, "Bình luận $i"));
        }
        $adminUser = $this->admin();
        $this->actingAs($adminUser)->get(route('admin.reports.index'));

        $count = function () use ($adminUser) {
            $n = 0;
            // Gate 9b: reports live on MongoDB; its command subscriber feeds the same QueryExecuted listener.
            \DB::connection('mongodb')->enableQueryLog();
            \DB::listen(function () use (&$n) {
                $n++;
            });
            $this->actingAs($adminUser)->get(route('admin.reports.index'))->assertOk();

            return $n;
        };
        $six = $count();
        for ($i = 6; $i < 12; $i++) {
            $this->report($reporter, $this->comment($author, $article, "Bình luận $i"));
        }
        $this->assertSame($six, $count());
    }

    // Rule 6 (D2)
    public function test_case_page_shows_snapshot_live_state_all_reports_history_and_article_link(): void
    {
        $article = $this->article('bai-co-link');
        $author = $this->user(['name' => 'Bị Báo Cáo']);
        $comment = $this->comment($author, $article, 'Nội dung bị báo cáo');
        $old = $this->sanctionFor($author, $this->admin(), 'warning', ['reason' => 'Cảnh cáo từ trước đó']);
        $first = $this->report($this->user(['name' => 'Người Một']), $comment, ['description' => 'mô tả một']);
        $this->report($this->user(['name' => 'Người Hai']), $comment, ['reason' => 'hate_speech']);

        $this->actingAs($this->admin())->get(route('admin.reports.show', $first))
            ->assertOk()
            ->assertSee('Nội dung bị báo cáo')->assertSee('Bị Báo Cáo')
            ->assertSee('Người Một')->assertSee('Người Hai')->assertSee('mô tả một')
            ->assertSee('Cảnh cáo từ trước đó')
            ->assertSee('Đang hiển thị')
            ->assertSee('/articles/bai-co-link', false);

        $comment->forceFill(['is_hidden' => true])->save();
        $this->actingAs($this->admin())->get(route('admin.reports.show', $first))->assertOk()->assertSee('Đang bị ẩn');
    }

    public function test_case_page_survives_deleted_comment_and_deleted_article(): void
    {
        $article = $this->article();
        $author = $this->user();
        $comment = $this->comment($author, $article, 'Sẽ bị xóa sau khi báo cáo');
        $report = $this->report($this->user(), $comment);
        $comment->delete();
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->get(route('admin.reports.show', $report))
            ->assertOk()->assertSee('Sẽ bị xóa sau khi báo cáo')->assertSee('Bình luận đã bị xóa');
        $this->actingAs($adminUser)->get(route('admin.reports.index'))->assertOk()->assertSee('Sẽ bị xóa sau khi báo cáo');

        $comment2 = $this->comment($author, $article, 'Bài viết sẽ biến mất');
        $report2 = $this->report($this->user(), $comment2);
        $article->delete();
        $this->actingAs($adminUser)->get(route('admin.reports.show', $report2))->assertOk()->assertSee('Bài viết không còn tồn tại');
        $this->actingAs($adminUser)->get(route('admin.reports.index'))->assertOk();
    }

    public function test_case_page_survives_deleted_author(): void
    {
        $author = $this->user();
        $report = $this->report($this->user(), $this->comment($author, $this->article(), 'Tác giả đã xóa'));
        $author->delete();

        $this->actingAs($this->admin())->get(route('admin.reports.show', $report))->assertOk()->assertSee('Người dùng đã xóa');
    }

    // Rule 7 (D7)
    public function test_dismiss_and_false_report_record_decision_and_resolve_all_pending_on_comment(): void
    {
        $article = $this->article();
        $comment = $this->comment($this->user(), $article);
        $other = $this->comment($this->user(), $article, 'Bình luận khác');
        $adminUser = $this->admin();
        $r1 = $this->report($this->user(), $comment);
        $r2 = $this->report($this->user(), $comment);
        $untouched = $this->report($this->user(), $other);

        $this->actingAs($adminUser)->put(route('admin.reports.update', $r1), ['status' => 'false_report', 'note' => 'Báo cáo lạm dụng'])
            ->assertSessionHas('success');

        foreach ([$r1, $r2] as $r) {
            $r = $r->fresh();
            $this->assertSame('false_report', $r->status);
            $this->assertSame((string) $adminUser->id, $r->admin_id);
            $this->assertNotNull($r->decided_at);
            $this->assertSame('Báo cáo lạm dụng', $r->decision_note);
        }
        $this->assertSame('pending', $untouched->fresh()->status);
        $this->assertFalse((bool) $comment->fresh()->is_hidden);
    }

    public function test_dismiss_without_note_is_allowed(): void
    {
        $report = $this->report($this->user(), $this->comment($this->user(), $this->article()));

        $this->actingAs($this->admin())->put(route('admin.reports.update', $report), ['status' => 'dismissed'])->assertSessionHas('success');
        $this->assertSame('dismissed', $report->fresh()->status);
        $this->assertNull($report->fresh()->decision_note);
    }

    public function test_only_pending_cases_can_be_decided_and_statuses_are_restricted(): void
    {
        $report = $this->report($this->user(), $this->comment($this->user(), $this->article()));
        $adminUser = $this->admin();

        foreach (['pending', 'reviewed', 'actioned', 'bogus'] as $bad) {
            $this->actingAs($adminUser)->put(route('admin.reports.update', $report), ['status' => $bad])->assertSessionHasErrors('status');
        }
        $this->assertSame('pending', $report->fresh()->status);

        $this->actingAs($adminUser)->put(route('admin.reports.update', $report), ['status' => 'dismissed']);
        $this->actingAs($adminUser)->put(route('admin.reports.update', $report), ['status' => 'false_report'])->assertSessionHas('error');
        $this->assertSame('dismissed', $report->fresh()->status);
    }

    public function test_reviewed_status_is_gone_and_legacy_rows_are_migrated_to_pending(): void
    {
        $this->assertFalse(method_exists(Report::class, 'scopeReviewed'));
        $this->assertSame('Không xác định', (new Report(['status' => 'reviewed']))->statusLabel());
    }

    public function test_report_and_sanction_cannot_be_hard_deleted_from_admin(): void
    {
        $report = $this->report($this->user(), $this->comment($this->user(), $this->article()));
        $adminUser = $this->admin();

        $this->assertFalse(\Route::has('admin.reports.destroy'));
        $this->assertFalse(\Route::has('admin.sanctions.destroy'));
        $this->actingAs($adminUser)->delete('/admin/reports/'.$report->id)->assertStatus(405);
        $this->assertSame(1, Report::count());
    }

    // Rule 8 (D4)
    public function test_sanction_from_case_actions_all_pending_reports_links_sanction_and_hides_when_chosen(): void
    {
        $article = $this->article();
        $author = $this->user();
        $comment = $this->comment($author, $article);
        $adminUser = $this->admin();
        $r1 = $this->report($this->user(), $comment);
        $r2 = $this->report($this->user(), $comment);
        $dismissedEarlier = $this->report($this->user(), $this->comment($author, $article, 'Khác'));

        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $r1->id, 'type' => 'temporary_ban',
            'reason' => 'Phát ngôn thù địch', 'duration_days' => 3, 'hide_comment' => '1', 'note' => 'Ghi chú nội bộ',
        ])->assertSessionHas('success');

        $sanction = Sanction::sole();
        $this->assertSame($r1->id, $sanction->report_id);
        foreach ([$r1, $r2] as $r) {
            $r = $r->fresh();
            $this->assertSame('actioned', $r->status);
            $this->assertSame($sanction->id, (string) $r->sanction_id); // Gate 9b: UUIDv7 sanction ids
            $this->assertSame((string) $adminUser->id, $r->admin_id);
            $this->assertNotNull($r->decided_at);
        }
        $this->assertSame('pending', $dismissedEarlier->fresh()->status);
        $this->assertTrue((bool) $comment->fresh()->is_hidden);
        $this->assertSame($sanction->id, (string) $comment->fresh()->hidden_by_sanction_id); // Gate 9b: UUIDv7 sanction ids
    }

    public function test_sanction_from_case_does_not_hide_unless_chosen(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);

        $this->actingAs($this->admin())->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'warning', 'reason' => 'Nhắc nhở',
        ])->assertSessionHas('success');

        $this->assertFalse((bool) $comment->fresh()->is_hidden);
        $this->assertSame('actioned', $report->fresh()->status);
    }

    public function test_sanction_from_case_rejects_wrong_user_and_non_pending_case(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $this->user()->id, 'report_id' => $report->id, 'type' => 'warning', 'reason' => 'Sai người',
        ])->assertSessionHas('error');
        $this->assertSame(0, Sanction::count());

        $this->actingAs($adminUser)->put(route('admin.reports.update', $report), ['status' => 'dismissed']);
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'warning', 'reason' => 'Đã đóng',
        ])->assertSessionHas('error');
        $this->assertSame(0, Sanction::count());
        $this->assertSame('dismissed', $report->fresh()->status);
    }

    public function test_sanction_without_case_from_user_page(): void
    {
        $user = $this->user();
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->get(route('admin.users.edit', $user))->assertOk()->assertSee(route('admin.sanctions.store'));
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $user->id, 'type' => 'mute', 'reason' => 'Gây rối', 'duration_days' => 2,
        ])->assertSessionHas('success');

        $s = Sanction::sole();
        $this->assertNull($s->report_id);
        $this->assertTrue($user->fresh()->isMuted());
    }

    public function test_non_admin_cannot_use_moderation_endpoints(): void
    {
        $user = $this->user();
        $report = $this->report($this->user(), $this->comment($this->user(), $this->article()));
        $sanction = $this->sanctionFor($user, $this->admin());

        $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.reports.update', $report), ['status' => 'dismissed'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.sanctions.store'), ['user_id' => (string) $user->id, 'type' => 'warning', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.sanctions.revoke', $sanction))->assertForbidden();
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(1, Sanction::count());
    }
}
