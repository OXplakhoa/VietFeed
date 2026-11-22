<?php

namespace Tests\Feature;

use App\Models\Report;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/** Gate 9a, user side: rules 1-4. */
class ModerationUserReportTest extends TestCase
{
    use ModerationFixtures;

    // Rule 1 (D1)
    public function test_article_page_renders_comments_with_author_name_and_report_control(): void
    {
        $article = $this->article();
        $author = $this->user(['name' => 'Tác Giả Một']);
        $reporter = $this->user();
        $this->comment($author, $article, 'Nội dung bình luận thứ nhất');

        $response = $this->actingAs($reporter)->get('/articles/'.$article->slug);

        $response->assertOk()
            ->assertSee('Tác Giả Một')
            ->assertSee('Nội dung bình luận thứ nhất')
            ->assertSee('Gửi báo cáo');
    }

    public function test_article_page_renders_comment_whose_author_was_deleted(): void
    {
        $article = $this->article();
        $author = $this->user();
        $this->comment($author, $article, 'Bình luận của người đã xóa tài khoản');
        $author->delete();

        $this->get('/articles/'.$article->slug)
            ->assertOk()
            ->assertSee('Bình luận của người đã xóa tài khoản')
            ->assertSee('Người dùng đã xóa');
    }

    public function test_article_page_shows_replies_with_author_names_to_guests(): void
    {
        $article = $this->article();
        $a = $this->user(['name' => 'Người Gốc']);
        $b = $this->user(['name' => 'Người Trả Lời']);
        $parent = $this->comment($a, $article, 'Bình luận gốc');
        $this->comment($b, $article, 'Câu trả lời', $parent->id);

        $this->get('/articles/'.$article->slug)
            ->assertOk()->assertSee('Người Gốc')->assertSee('Người Trả Lời')->assertSee('Câu trả lời');
    }

    public function test_report_control_is_not_offered_to_ineligible_users(): void
    {
        $article = $this->article();
        $author = $this->user();
        $comment = $this->comment($author, $article);

        $this->actingAs($this->admin())->get('/articles/'.$article->slug)->assertOk()->assertDontSee('Gửi báo cáo');
        $this->actingAs($author)->get('/articles/'.$article->slug)->assertOk()->assertDontSee('Gửi báo cáo');
        $this->actingAs($this->user())->get('/articles/'.$article->slug)->assertOk()->assertSee('report-modal-'.$comment->id);

        $banned = $this->user();
        $this->sanctionFor($banned, $this->admin(), 'temporary_ban');
        $this->actingAs($banned)->get('/articles/'.$article->slug)->assertOk()->assertDontSee('Gửi báo cáo');

        $unverified = $this->user(['email_verified_at' => null]);
        $this->actingAs($unverified)->get('/articles/'.$article->slug)->assertOk()->assertDontSee('Gửi báo cáo');
    }

    // Rule 2
    public function test_verified_user_reports_comment_and_snapshot_is_stored(): void
    {
        $article = $this->article();
        $author = $this->user();
        $reporter = $this->user();
        $comment = $this->comment($author, $article, 'Nội dung sẽ được chụp lại');

        $this->actingAs($reporter)->from('/articles/'.$article->slug)
            ->post(route('reports.store', $comment), ['reason' => 'harassment', 'description' => str_repeat('a', 2000)])
            ->assertRedirect('/articles/'.$article->slug)
            ->assertSessionHas('success');

        $report = Report::sole();
        $this->assertSame('pending', $report->status);
        $this->assertSame('harassment', $report->reason);
        $this->assertSame((string) $reporter->id, $report->reporter_id);
        $this->assertSame('Nội dung sẽ được chụp lại', $report->comment_body);
        $this->assertSame((string) $author->id, $report->comment_user_id);
    }

    public function test_muted_user_can_still_report(): void
    {
        $article = $this->article();
        $comment = $this->comment($this->user(), $article);
        $muted = $this->user();
        $this->sanctionFor($muted, $this->admin(), 'mute');

        $this->actingAs($muted)->post(route('reports.store', $comment), ['reason' => 'spam'])->assertSessionHas('success');
        $this->assertSame(1, Report::count());
    }

    public function test_report_validates_reason_and_description_length(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $reporter = $this->user();

        $this->actingAs($reporter)->post(route('reports.store', $comment), ['reason' => 'nope'])->assertSessionHasErrors('reason');
        $this->actingAs($reporter)->post(route('reports.store', $comment), ['reason' => 'spam', 'description' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('description');
        $this->assertSame(0, Report::count());
    }

    // Rule 3
    public function test_rejected_reports_leave_no_record_and_explain_why(): void
    {
        $article = $this->article();
        $author = $this->user();
        $comment = $this->comment($author, $article);
        $adminUser = $this->admin();
        $reporter = $this->user();

        $this->actingAs($author)->post(route('reports.store', $comment), ['reason' => 'spam'])
            ->assertSessionHas('error', 'Bạn không thể báo cáo bình luận của chính mình.');
        $this->actingAs($adminUser)->post(route('reports.store', $comment), ['reason' => 'spam'])
            ->assertSessionHas('error');
        $this->actingAs($this->user(['email_verified_at' => null]))->post(route('reports.store', $comment), ['reason' => 'spam'])
            ->assertSessionHas('error');
        $tempBanned = $this->user();
        $this->sanctionFor($tempBanned, $adminUser, 'temporary_ban');
        $this->actingAs($tempBanned)->post(route('reports.store', $comment), ['reason' => 'spam'])->assertSessionHas('error');
        $permBanned = $this->user();
        $this->sanctionFor($permBanned, $adminUser, 'permanent_ban');
        $this->actingAs($permBanned)->post(route('reports.store', $comment), ['reason' => 'spam'])->assertRedirect(route('sanctions.banned'));
        $this->assertSame(0, Report::count());

        $this->actingAs($reporter)->post(route('reports.store', $comment), ['reason' => 'spam'])->assertSessionHas('success');
        $this->actingAs($reporter)->post(route('reports.store', $comment), ['reason' => 'other'])
            ->assertSessionHas('error', 'Bạn đã báo cáo bình luận này rồi.');
        $this->assertSame(1, Report::count());
    }

    public function test_hidden_and_deleted_comments_cannot_be_reported(): void
    {
        $article = $this->article();
        $comment = $this->comment($this->user(), $article);
        $comment->forceFill(['is_hidden' => true])->save();
        $reporter = $this->user();

        $this->actingAs($reporter)->post(route('reports.store', $comment), ['reason' => 'spam'])->assertSessionHas('error');

        $gone = $this->comment($this->user(), $article);
        $goneId = $gone->id;
        $gone->delete();
        $this->actingAs($reporter)->post('/comments/'.$goneId.'/report', ['reason' => 'spam'])->assertSessionHas('error');

        $this->assertSame(0, Report::count());
    }

    // Rule 4
    public function test_sanctioned_user_sees_active_sanction_and_appeals_once(): void
    {
        $adminUser = $this->admin();
        $user = $this->user();
        $sanction = $this->sanctionFor($user, $adminUser, 'mute', ['reason' => 'Lý do kiểm tra hồ sơ']);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()->assertSee('Cấm bình luận')->assertSee('Lý do kiểm tra hồ sơ')->assertSee('Hết hạn');

        $this->actingAs($user)->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'ngắn'])->assertSessionHasErrors('appeal_message');
        $this->actingAs($user)->post(route('sanctions.appeal', $sanction), ['appeal_message' => str_repeat('a', 2001)])->assertSessionHasErrors('appeal_message');

        $this->actingAs($user)->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Tôi xin được xem xét lại'])->assertSessionHas('success');
        $this->assertSame('pending', $sanction->fresh()->appeal_status);

        $this->actingAs($user)->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Gửi lần thứ hai nhé'])->assertForbidden();
        $this->assertSame('Tôi xin được xem xét lại', $sanction->fresh()->appeal_message);
    }

    public function test_appeal_on_someone_elses_sanction_is_refused(): void
    {
        $sanction = $this->sanctionFor($this->user(), $this->admin(), 'mute');

        $this->actingAs($this->user())->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Không phải của tôi nhưng thử'])->assertForbidden();
        $this->assertSame('none', $sanction->fresh()->appeal_status);
    }

    public function test_appeal_on_inactive_or_expired_sanction_is_refused(): void
    {
        $adminUser = $this->admin();
        $user = $this->user();
        $expired = $this->sanctionFor($user, $adminUser, 'mute', ['expires_at' => now()->subDay()]);
        $revoked = $this->sanctionFor($user, $adminUser, 'warning', ['is_active' => false]);

        foreach ([$expired, $revoked] as $s) {
            $this->actingAs($user)->post(route('sanctions.appeal', $s), ['appeal_message' => 'Đã hết hiệu lực rồi mà'])->assertSessionHas('error');
            $this->assertSame('none', $s->fresh()->appeal_status);
        }
    }
}
