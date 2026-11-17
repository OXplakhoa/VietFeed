<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Report;
use App\Models\Sanction;
use App\Moderation\ModerationException;
use App\Moderation\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/** Gate 9a: rules 10-12 (sanction list, appeal review, revoke). */
class ModerationSanctionTest extends TestCase
{
    use ModerationFixtures, RefreshDatabase;

    // Rule 10 (D3, D2)
    public function test_sanction_list_filters_by_type_and_status(): void
    {
        $adminUser = $this->admin();
        $u = $this->user(['name' => 'Người Một']);
        $this->sanctionFor($u, $adminUser, 'warning', ['reason' => 'lý do cảnh cáo']);
        $this->sanctionFor($u, $adminUser, 'mute', ['reason' => 'lý do mute hết hạn', 'expires_at' => now()->subDay()]);
        $this->sanctionFor($u, $adminUser, 'temporary_ban', ['reason' => 'lý do đã thu hồi', 'is_active' => false, 'revoked_at' => now(), 'revoked_by' => (string) $adminUser->id]);
        $this->sanctionFor($u, $adminUser, 'permanent_ban', ['reason' => 'lý do chờ kháng cáo', 'appeal_status' => 'pending', 'appeal_message' => 'xin xem xét']);

        $see = fn (array $q) => $this->actingAs($adminUser)->get(route('admin.sanctions.index', $q))->assertOk();

        $see(['type' => 'mute'])->assertSee('lý do mute hết hạn')->assertDontSee('lý do cảnh cáo');
        $see(['status' => 'active'])->assertSee('lý do cảnh cáo')->assertSee('lý do chờ kháng cáo')
            ->assertDontSee('lý do mute hết hạn')->assertDontSee('lý do đã thu hồi');
        $see(['status' => 'expired'])->assertSee('lý do mute hết hạn')->assertDontSee('lý do đã thu hồi')->assertDontSee('lý do cảnh cáo');
        $see(['status' => 'revoked'])->assertSee('lý do đã thu hồi')->assertDontSee('lý do mute hết hạn');
        $see(['status' => 'pending_appeal'])->assertSee('lý do chờ kháng cáo')->assertDontSee('lý do cảnh cáo');
    }

    public function test_sanction_list_search_by_user_name_or_reason_without_error(): void
    {
        $adminUser = $this->admin();
        $this->sanctionFor($this->user(['name' => 'Trần Văn Đặc Biệt']), $adminUser, 'warning', ['reason' => 'abc']);
        $this->sanctionFor($this->user(['name' => 'Người Khác']), $adminUser, 'warning', ['reason' => 'phát ngôn xấu']);

        $this->actingAs($adminUser)->get(route('admin.sanctions.index', ['q' => 'Đặc Biệt']))
            ->assertOk()->assertSee('Trần Văn Đặc Biệt')->assertDontSee('Người Khác');
        $this->actingAs($adminUser)->get(route('admin.sanctions.index', ['q' => 'phát ngôn']))
            ->assertOk()->assertSee('Người Khác')->assertDontSee('Trần Văn Đặc Biệt');
        $this->actingAs($adminUser)->get(route('admin.sanctions.index', ['q' => '(*[']))->assertOk();
        $this->actingAs($adminUser)->get(route('admin.sanctions.index', ['q' => 'Đặc Biệt', 'type' => 'mute']))
            ->assertOk()->assertDontSee('Trần Văn Đặc Biệt');
    }

    public function test_sanction_page_renders_when_comment_or_article_is_gone(): void
    {
        $article = $this->article();
        $author = $this->user();
        $comment = $this->comment($author, $article, 'Bình luận sẽ mất');
        $report = $this->report($this->user(), $comment);
        $adminUser = $this->admin();
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'mute', 'reason' => 'x', 'hide_comment' => 1,
        ]);
        $sanction = Sanction::sole();

        $this->actingAs($adminUser)->get(route('admin.sanctions.show', $sanction))->assertOk()->assertSee('Bình luận sẽ mất');
        $comment->delete();
        $this->actingAs($adminUser)->get(route('admin.sanctions.show', $sanction))->assertOk()->assertSee('Bình luận sẽ mất');
        $article->delete();
        $this->actingAs($adminUser)->get(route('admin.sanctions.show', $sanction))->assertOk();
    }

    // Rule 11 (D8)
    public function test_approved_appeal_revokes_sanction_unhides_comment_and_keeps_case_actioned(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $adminUser = $this->admin();
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'temporary_ban',
            'reason' => 'Vi phạm', 'duration_days' => 3, 'hide_comment' => 1,
        ]);
        $sanction = Sanction::sole();
        $this->assertTrue($comment->fresh()->is_hidden);
        $this->assertTrue($author->fresh()->isBanned());

        $this->actingAs($author->fresh())->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Tôi bị oan, xin xem lại']);
        $this->actingAs($adminUser)->put(route('admin.sanctions.update', $sanction), ['appeal_status' => 'approved', 'note' => 'Đồng ý'])
            ->assertSessionHas('success');

        $s = $sanction->fresh();
        $this->assertSame('approved', $s->appeal_status);
        $this->assertFalse($s->is_active);
        $this->assertNotNull($s->revoked_at);
        $this->assertSame((string) $adminUser->id, $s->revoked_by);
        $this->assertSame('Đồng ý', $s->revoke_note);
        $this->assertFalse((bool) $comment->fresh()->is_hidden);
        $this->assertNull($comment->fresh()->hidden_by_sanction_id);
        $this->assertSame('actioned', $report->fresh()->status);
        $this->assertFalse($author->fresh()->isBanned());
    }

    public function test_approving_appeal_does_not_unhide_a_comment_hidden_by_another_sanction(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $adminUser = $this->admin();
        $first = $this->sanctionFor($author, $adminUser, 'warning', ['appeal_status' => 'pending', 'appeal_message' => 'xin xem xét lại']);
        $second = $this->sanctionFor($author, $adminUser, 'warning');
        $comment->forceFill(['is_hidden' => true, 'hidden_by_sanction_id' => $second->id])->save();

        $this->actingAs($adminUser)->put(route('admin.sanctions.update', $first), ['appeal_status' => 'approved']);

        $this->assertTrue((bool) $comment->fresh()->is_hidden);
    }

    public function test_denied_appeal_leaves_sanction_and_comment_unchanged(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $adminUser = $this->admin();
        $sanction = $this->sanctionFor($author, $adminUser, 'temporary_ban', ['appeal_status' => 'pending', 'appeal_message' => 'xin xem xét lại']);
        $comment->forceFill(['is_hidden' => true, 'hidden_by_sanction_id' => $sanction->id])->save();

        $this->actingAs($adminUser)->put(route('admin.sanctions.update', $sanction), ['appeal_status' => 'denied', 'note' => 'Giữ nguyên'])
            ->assertSessionHas('success');

        $s = $sanction->fresh();
        $this->assertSame('denied', $s->appeal_status);
        $this->assertTrue($s->is_active);
        $this->assertNull($s->revoked_at);
        $this->assertTrue((bool) $comment->fresh()->is_hidden);
        $this->assertTrue($author->fresh()->isBanned());
    }

    public function test_only_pending_appeals_can_be_decided(): void
    {
        $adminUser = $this->admin();
        $none = $this->sanctionFor($this->user(), $adminUser, 'mute');
        $done = $this->sanctionFor($this->user(), $adminUser, 'mute', ['appeal_status' => 'denied', 'appeal_message' => 'đã bị từ chối']);

        foreach ([$none, $done] as $s) {
            $this->actingAs($adminUser)->put(route('admin.sanctions.update', $s), ['appeal_status' => 'approved'])->assertSessionHas('error');
            $this->assertTrue($s->fresh()->is_active);
        }
        $this->assertSame('denied', $done->fresh()->appeal_status);
        $this->actingAs($adminUser)->put(route('admin.sanctions.update', $none), ['appeal_status' => 'pending'])->assertSessionHasErrors('appeal_status');
    }

    // Rule 12 (D6)
    public function test_revoke_keeps_record_with_admin_when_and_note(): void
    {
        $user = $this->user();
        $adminUser = $this->admin();
        $sanction = $this->sanctionFor($user, $adminUser, 'permanent_ban');
        $this->assertTrue($user->isPermanentlyBanned());

        $this->actingAs($adminUser)->post(route('admin.sanctions.revoke', $sanction), ['note' => 'Xử lý nhầm'])->assertSessionHas('success');

        $s = $sanction->fresh();
        $this->assertFalse($s->is_active);
        $this->assertNotNull($s->revoked_at);
        $this->assertSame((string) $adminUser->id, $s->revoked_by);
        $this->assertSame('Xử lý nhầm', $s->revoke_note);
        $this->assertSame(1, Sanction::count());
        $this->assertFalse($user->fresh()->isPermanentlyBanned());

        $this->actingAs($adminUser)->get(route('admin.sanctions.show', $s))->assertOk()->assertSee('Xử lý nhầm');
        $this->actingAs($adminUser)->post(route('admin.sanctions.revoke', $s), ['note' => 'lần hai'])->assertSessionHas('error');
        $this->assertSame('Xử lý nhầm', $s->fresh()->revoke_note);
    }

    public function test_revoke_unhides_comment_hidden_by_that_sanction_and_report_survives(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $adminUser = $this->admin();
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'mute', 'reason' => 'x', 'hide_comment' => 1,
        ]);
        $sanction = Sanction::sole();
        $this->assertTrue($comment->fresh()->is_hidden);

        $this->actingAs($adminUser)->post(route('admin.sanctions.revoke', $sanction), ['note' => 'Nhầm lẫn']);

        $this->assertFalse($comment->fresh()->is_hidden);
        $this->assertNull($comment->fresh()->hidden_by_sanction_id);
        $this->actingAs($this->user())->get('/articles/'.Article::find($comment->article_id)->slug)
            ->assertOk()->assertSee('Bình luận cần xem xét');
        $this->assertSame(1, Report::count());
        $this->assertSame('actioned', $report->fresh()->status);
        $this->actingAs($adminUser)->get(route('admin.reports.show', $report))->assertOk()->assertSee('Đã thu hồi')->assertSee('Đang hiển thị');
    }

    public function test_revoke_keeps_hidden_a_comment_hidden_by_a_different_active_sanction(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $adminUser = $this->admin();
        $first = $this->sanctionFor($author, $adminUser, 'warning');
        $second = $this->sanctionFor($author, $adminUser, 'warning');
        $comment->forceFill(['is_hidden' => true, 'hidden_by_sanction_id' => $second->id])->save();

        $this->actingAs($adminUser)->post(route('admin.sanctions.revoke', $first));

        $this->assertTrue($comment->fresh()->is_hidden);
        $this->assertTrue($second->fresh()->is_active);
    }

    // R3
    public function test_second_appeal_submit_with_stale_model_does_not_overwrite_first_message(): void
    {
        $user = $this->user();
        $sanction = $this->sanctionFor($user, $this->admin(), 'mute');
        $stale = Sanction::find($sanction->id);

        $this->actingAs($user)->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Lần gửi đầu tiên'])->assertSessionHas('success');

        $this->expectException(ModerationException::class);
        try {
            app(ModerationService::class)->submitAppeal($user, $stale, 'Lần gửi thứ hai ghi đè');
        } finally {
            $this->assertSame('Lần gửi đầu tiên', $sanction->fresh()->appeal_message);
        }
    }

    // R4
    public function test_hiding_does_not_take_over_a_comment_already_hidden_by_another_sanction(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $adminUser = $this->admin();
        $first = $this->sanctionFor($author, $adminUser, 'warning');
        $comment->forceFill(['is_hidden' => true, 'hidden_by_sanction_id' => $first->id])->save();
        $report = Report::create(['reporter_id' => 'x', 'comment_id' => $comment->id, 'reason' => 'spam',
            'comment_body' => $comment->body, 'comment_user_id' => (string) $author->id, 'comment_article_id' => $comment->article_id]);

        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'warning', 'reason' => 'Lần hai', 'hide_comment' => 1,
        ])->assertSessionHas('success');

        $this->assertSame($first->id, (int) $comment->fresh()->hidden_by_sanction_id);
        $this->assertTrue($comment->fresh()->is_hidden);
    }

    // R5
    public function test_second_action_on_another_report_of_the_same_comment_is_refused_cleanly(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $r1 = $this->report($this->user(), $comment);
        $r2 = $this->report($this->user(), $comment);
        $adminUser = $this->admin();

        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $r1->id, 'type' => 'warning', 'reason' => 'Một',
        ])->assertSessionHas('success');
        $this->actingAs($adminUser)->put(route('admin.reports.update', $r2), ['status' => 'dismissed'])->assertSessionHas('error');
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $r2->id, 'type' => 'mute', 'reason' => 'Hai',
        ])->assertSessionHas('error');

        $this->assertSame(1, Sanction::count());
        $this->assertSame('actioned', $r2->fresh()->status);
    }

    // R7
    public function test_case_page_shows_linked_sanction_appeal_status_and_decision_note(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $adminUser = $this->admin();
        $this->actingAs($adminUser)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'temporary_ban', 'reason' => 'x',
        ]);
        $sanction = Sanction::sole();
        $show = fn () => $this->actingAs($adminUser)->get(route('admin.reports.show', $report))->assertOk();

        $show()->assertSee('Chưa kháng cáo');
        $this->actingAs($author->fresh())->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Xin xem xét lại giúp']);
        $show()->assertSee('Chờ duyệt');
        $this->actingAs($adminUser)->put(route('admin.sanctions.update', $sanction), ['appeal_status' => 'denied', 'note' => 'Giữ nguyên quyết định cũ']);
        $show()->assertSee('Bị từ chối')->assertSee('Giữ nguyên quyết định cũ');
    }

    // R8
    public function test_case_page_decision_form_targets_pending_reports_regardless_of_url_report(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $adminUser = $this->admin();
        $r1 = $this->report($this->user(), $comment);
        $this->actingAs($adminUser)->put(route('admin.reports.update', $r1), ['status' => 'dismissed']);
        $r2 = $this->report($this->user(), $comment);

        $this->actingAs($adminUser)->get(route('admin.reports.show', $r1))->assertOk()
            ->assertSee(route('admin.reports.update', $r2), false);
        $this->actingAs($adminUser)->put(route('admin.reports.update', $r2), ['status' => 'false_report'])->assertSessionHas('success');
        $this->assertSame('false_report', $r2->fresh()->status);
        $this->assertSame('dismissed', $r1->fresh()->status);
    }
}
