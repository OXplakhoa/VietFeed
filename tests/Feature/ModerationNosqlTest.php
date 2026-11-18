<?php

namespace Tests\Feature;

use App\ApiTokens\ApiTokenService;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use App\Moderation\ModerationAudit;
use App\Moderation\ModerationCases;
use App\Moderation\ModerationException;
use App\Moderation\ModerationQueue;
use App\Moderation\ModerationService;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/**
 * Gate 9b: moderation on MongoDB (case-document atomicity), Redis review queue (projection) and
 * Cassandra audit (moderation_events_by_case). Isolated stores only: Mongo vietfeed_test, Redis DB15,
 * Cassandra keyspace vietfeed_test.
 */
class ModerationNosqlTest extends TestCase
{
    use ModerationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.moderation_retry_ms' => 1]);
        $this->cassandraPort = (int) config('nosql.cassandra.port');
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        app(ModerationAudit::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
            config(['nosql.cassandra.port' => $this->cassandraPort]);
            $partitions = DB::connection('mongodb')->table('moderation_cases')->pluck('_id')
                ->merge(DB::connection('mongodb')->table('sanctions')->pluck('audit_case_id'))->filter()->unique();
            foreach ($partitions as $id) {
                app(CassandraClient::class)->execute('DELETE FROM vietfeed_test.moderation_events_by_case WHERE case_id = ?', [new Uuid((string) $id)]);
            }
        } finally {
            parent::tearDown();
        }
    }

    private int $cassandraPort = 9042;

    private function service(): ModerationService
    {
        return app(ModerationService::class);
    }

    private function cases(): ModerationCases
    {
        return app(ModerationCases::class);
    }

    private function caseOf(Report $report): array
    {
        return $this->cases()->find((string) $report->fresh()->case_id);
    }

    private function consumeAll(): int
    {
        $total = 0;
        while (($n = app(ModerationAudit::class)->consume('test', 100)) > 0) {
            $total += $n;
        }

        return $total;
    }

    /** Fail every delivery until the shared StreamConsumer moves it to the DLQ (attempt 5). */
    private function drainToDeadLetters(): void
    {
        $stream = app(ModerationAudit::class)->stream();
        for ($i = 0; $i < 7; $i++) {
            $stream->consume('down', 100, fn (array $event) => app(ModerationAudit::class)->apply($event));
            foreach ($stream->pending() as $pending) {
                Redis::connection('nosql')->client()->xClaim(ModerationAudit::STREAM, ModerationAudit::GROUP, $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            }
        }
    }

    // --- Case atomicity -------------------------------------------------------------------------

    public function test_double_resolve_of_the_same_case_produces_exactly_one_outcome(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $r1 = $this->report($this->user(), $comment);
        $r2 = $this->report($this->user(), $comment);
        [$adminA, $adminB] = [$this->admin(), $this->admin()];
        $staleA = Report::find($r1->id);
        $staleB = Report::find($r2->id);

        $this->assertSame(2, $this->service()->resolveCase($adminA, $staleA, 'dismissed', 'A'));
        try {
            $this->service()->resolveCase($adminB, $staleB, 'false_report', 'B');
            $this->fail('Second decision must be refused');
        } catch (ModerationException $e) {
            $this->assertSame(ModerationCases::DECIDED, $e->getMessage());
        }
        // A writer that read the case before the first decision loses the conditional update too.
        try {
            $this->cases()->decide((string) $r1->fresh()->case_id, 'false_report', (string) $adminB->id, 'late');
            $this->fail('Stale decision must be refused');
        } catch (ModerationException) {
        }

        $case = $this->caseOf($r1);
        $this->assertSame('dismissed', $case['status']);
        $this->assertCount(1, $case['decisions']);
        $this->assertSame(1, collect($case['events'])->where('type', 'CASE_DISMISSED')->count());
        $this->assertSame(0, collect($case['events'])->where('type', 'CASE_FALSE_REPORT')->count());
        foreach ([$r1, $r2] as $r) {
            $this->assertSame('dismissed', $r->fresh()->status);
            $this->assertSame('A', $r->fresh()->decision_note);
        }
    }

    public function test_resolve_while_a_sanction_claim_is_in_flight_is_refused_and_one_outcome_wins(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $r1 = $this->report($this->user(), $comment);
        $r2 = $this->report($this->user(), $comment);
        $admin = $this->admin();
        $caseId = (string) $r1->fresh()->case_id;

        // Request A claimed the case for sanction S and has not inserted S yet.
        $sanctionId = (string) Str::uuid7();
        [$opId] = $this->cases()->decide($caseId, 'actioned', (string) $admin->id, null, ['sanction_id' => $sanctionId, 'hide' => false]);

        // Request B (dismiss) and request C (another sanction) are refused, nothing written.
        foreach ([fn () => $this->service()->resolveCase($admin, $r2, 'dismissed', null),
            fn () => $this->service()->issueSanction($admin, $author, 'mute', 'x', 3, (string) $r2->id)] as $attempt) {
            try {
                $attempt();
                $this->fail('Concurrent decision must be refused');
            } catch (ModerationException $e) {
                $this->assertSame(ModerationCases::BUSY, $e->getMessage());
            }
        }
        $this->assertSame(0, Sanction::count());
        $this->assertSame(['pending', 'pending'], [$r1->fresh()->status, $r2->fresh()->status]);

        // A finishes: sanction exists -> repair after the lease (it cannot go below 60 s) confirms it.
        $sanction = new Sanction(['user_id' => (string) $author->id, 'admin_id' => (string) $admin->id, 'type' => 'warning', 'reason' => 'A', 'is_active' => true, 'report_id' => (string) $r1->id]);
        $sanction->forceFill(['_id' => $sanctionId])->save();
        $this->assertSame(0, $this->service()->repair(0)['confirmed'], 'lease is clamped to the 60 s minimum');
        $this->travel(2)->minutes();
        $this->assertSame(1, $this->service()->repair(60)['confirmed']);
        $this->travelBack();

        $case = $this->cases()->find($caseId);
        $this->assertSame('actioned', $case['status']);
        $this->assertSame('confirmed', collect($case['decisions'])->firstWhere('op_id', $opId)['state']);
        foreach ([$r1, $r2] as $r) {
            $this->assertSame('actioned', $r->fresh()->status);
            $this->assertSame($sanctionId, $r->fresh()->sanction_id);
        }
        $this->expectException(ModerationException::class);
        $this->service()->resolveCase($admin, $r2, 'dismissed', null);
    }

    public function test_failed_sanction_insert_releases_the_claim_and_reports_arriving_meanwhile_are_kept(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $r1 = $this->report($this->user(), $comment, ['reason' => 'harassment']);
        $admin = $this->admin();
        $caseId = (string) $r1->fresh()->case_id;
        $before = $this->cases()->find($caseId);

        [$opId] = $this->cases()->decide($caseId, 'actioned', (string) $admin->id, null, ['sanction_id' => (string) Str::uuid7(), 'hide' => true]);
        $r2 = $this->report($this->user(), $comment); // arrives while the claim is in flight: new round
        $this->assertSame('pending', $this->cases()->find($caseId)['status']);
        $this->assertTrue($this->cases()->release($caseId, $opId));
        $this->assertFalse($this->cases()->release($caseId, $opId), 'release is idempotent');

        $case = $this->cases()->find($caseId);
        $this->assertSame('pending', $case['status']);
        $this->assertSame(2, $case['pending_count']);
        $this->assertSame(2, $case['severity']);
        $this->assertEquals($before['round_started_at'], $case['round_started_at']);
        $this->assertSame([], $case['decisions']);

        // The next decision covers both rounds.
        $this->assertSame(2, $this->service()->resolveCase($admin, $r2, 'dismissed', null));
        $this->assertSame(['dismissed', 'dismissed'], [$r1->fresh()->status, $r2->fresh()->status]);
        $this->assertFalse((bool) $comment->fresh()->is_hidden);
    }

    public function test_real_insert_failure_during_sanction_from_case_releases_claim_with_no_sanction(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $admin = $this->admin();
        User::where('_id', $author->id)->update(['api_token_epoch' => 'forced-increment-failure']);

        $this->actingAs($admin)->from('/admin/reports')->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'permanent_ban', 'reason' => 'x', 'hide_comment' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(0, Sanction::count());
        $case = $this->caseOf($report);
        $this->assertSame('pending', $case['status']);
        $this->assertSame([], $case['decisions']);
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertFalse((bool) $comment->fresh()->is_hidden);
    }

    public function test_repair_converges_every_interrupted_step(): void
    {
        $admin = $this->admin();
        $article = $this->article();

        // (a) crash after claim, before sanction insert: fresh claim untouched, stale claim released.
        $a = $this->report($this->user(), $this->comment($this->user(), $article, 'a'));
        $aCase = (string) $a->fresh()->case_id;
        $this->cases()->decide($aCase, 'actioned', (string) $admin->id, null, ['sanction_id' => (string) Str::uuid7(), 'hide' => false]);
        $this->service()->repair();
        $this->assertSame('actioned', $this->cases()->find($aCase)['status']);
        $this->travel(11)->minutes();
        $this->assertSame(1, $this->service()->repair()['released']);
        $this->assertSame('pending', $this->cases()->find($aCase)['status']);
        $this->travelBack();

        // (b) crash after sanction insert, before confirm: confirmed, reports settled, comment hidden.
        $bAuthor = $this->user();
        $bComment = $this->comment($bAuthor, $article, 'b');
        $b = $this->report($this->user(), $bComment);
        $bCase = (string) $b->fresh()->case_id;
        $sanctionId = (string) Str::uuid7();
        $this->cases()->decide($bCase, 'actioned', (string) $admin->id, null, ['sanction_id' => $sanctionId, 'hide' => true]);
        (new Sanction(['user_id' => (string) $bAuthor->id, 'admin_id' => (string) $admin->id, 'type' => 'mute', 'reason' => 'b', 'is_active' => true, 'expires_at' => now()->addDay()]))
            ->forceFill(['_id' => $sanctionId])->save();
        $this->travel(11)->minutes();
        $this->assertSame(1, $this->service()->repair()['confirmed']);
        $this->travelBack();
        $this->assertSame('actioned', $b->fresh()->status);
        $this->assertTrue((bool) $bComment->fresh()->is_hidden);
        $this->assertSame($sanctionId, $bComment->fresh()->hidden_by_sanction_id);
        $this->assertSame(1, collect(Sanction::find($sanctionId)->events)->where('type', 'COMMENT_HIDDEN')->count());

        // (c) crash after a dismiss decision, before the report documents were settled: the case page converges it.
        $c = $this->report($this->user(), $this->comment($this->user(), $article, 'c'));
        $this->cases()->decide((string) $c->fresh()->case_id, 'dismissed', (string) $admin->id, 'c');
        $this->assertSame('pending', $c->fresh()->status);
        $this->actingAs($admin)->get(route('admin.reports.show', $c))->assertOk();
        $this->assertSame('dismissed', $c->fresh()->status);

        // (d) crash after revoke, before the SQL unhide: the marker drives the unhide.
        $dAuthor = $this->user();
        $dComment = $this->comment($dAuthor, $article, 'd');
        $d = $this->sanctionFor($dAuthor, $admin, 'warning');
        $dComment->forceFill(['is_hidden' => true, 'hidden_by_sanction_id' => $d->id])->save();
        DB::connection('mongodb')->table('sanctions')->where('_id', $d->id)->update(['is_active' => false, 'revoked_at' => now(), 'unhide_pending' => true]);
        $this->assertSame(1, $this->service()->repair()['unhidden']);
        $this->assertFalse((bool) $dComment->fresh()->is_hidden);
        $this->assertNull(Sanction::find($d->id)->unhide_pending);

        // (e) crash between report insert and case registration: repair registers it exactly once.
        $eComment = $this->comment($this->user(), $article, 'e');
        $e = Report::withoutEvents(fn () => Report::create(['reporter_id' => 'reporter-e', 'comment_id' => $eComment->id, 'reason' => 'spam']));
        $this->assertNull($e->case_id);
        $this->assertSame(1, $this->service()->repair()['registered']);
        $this->assertSame(0, $this->service()->repair()['registered']);
        $case = $this->caseOf($e);
        $this->assertSame(['pending', 1, 1], [$case['status'], $case['pending_count'], $case['reports_count']]);
    }

    // --- Fix round (REVIEW-1) ----------------------------------------------------------------------

    /** F1: crash after the SQL hide and before the revoke re-check, in both marker states. */
    public function test_repair_unhides_comments_left_hidden_by_a_revoked_sanction(): void
    {
        $admin = $this->admin();
        $article = $this->article();
        $hidden = [];
        foreach (['requested', 'applied'] as $state) {
            $author = $this->user();
            $comment = $this->comment($author, $article, "crash $state");
            $report = $this->report($this->user(), $comment);
            $caseId = (string) $report->fresh()->case_id;
            $sanctionId = (string) Str::uuid7();
            [$opId] = $this->cases()->decide($caseId, 'actioned', (string) $admin->id, null, ['sanction_id' => $sanctionId, 'hide' => true]);
            (new Sanction(['user_id' => (string) $author->id, 'admin_id' => (string) $admin->id, 'type' => 'warning', 'reason' => $state, 'is_active' => true]))
                ->forceFill(['_id' => $sanctionId, 'case_id' => $caseId, 'audit_case_id' => $caseId])->save();
            $this->cases()->confirm($caseId, $opId);
            // The SQL hide landed, then a revoke (which found nothing to unhide yet), then the process died.
            Comment::where('id', $comment->id)->update(['is_hidden' => true, 'hidden_by_sanction_id' => $sanctionId]);
            DB::connection('mongodb')->table('sanctions')->where('_id', $sanctionId)->update(['is_active' => false, 'revoked_at' => now()]);
            if ($state === 'applied') {
                $this->cases()->markHide($caseId, $opId, 'applied');
            }
            $hidden[$state] = [$comment, $sanctionId, $caseId, $opId];
        }

        $done = $this->service()->repair();
        $this->assertSame(1, $done['unhidden_revoked'], 'the applied-state comment is found by the sweep');
        foreach ($hidden as $state => [$comment, $sanctionId, $caseId, $opId]) {
            $this->assertFalse((bool) $comment->fresh()->is_hidden, $state);
            $this->assertNull($comment->fresh()->hidden_by_sanction_id, $state);
            $this->assertSame(1, collect(Sanction::find($sanctionId)->events)->where('type', 'COMMENT_UNHIDDEN')->count(), $state);
        }
        $this->assertSame('skipped', $this->cases()->decision($hidden['requested'][2], $hidden['requested'][3])['hide_state']);
        $this->assertSame(0, $this->service()->repair()['unhidden_revoked'], 'idempotent');
    }

    /** F2: the sanction request stalls past the lease, repair releases the claim, then the insert lands. */
    public function test_request_stalled_past_the_lease_reclaims_the_case_or_flags_an_orphan(): void
    {
        $admin = $this->admin();
        foreach (['reclaim', 'orphan'] as $variant) {
            $author = $this->user();
            $comment = $this->comment($author, $this->article("bai-$variant"), $variant);
            $report = $this->report($this->user(), $comment);
            unset($fired); // each listener gets its own by-reference flag
            $fired = false;
            Sanction::creating(function () use (&$fired, $variant, $admin, $report) {
                if ($fired) {
                    return;
                }
                $fired = true;
                $this->travel(11)->minutes();
                $this->assertSame(1, $this->service()->repair()['released']);
                if ($variant === 'orphan') {
                    $this->service()->resolveCase($admin, $report, 'dismissed', 'decided while stalled');
                }
            });
            $this->actingAs($admin)->post(route('admin.sanctions.store'), [
                'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'mute', 'reason' => $variant, 'duration_days' => 3,
            ])->assertSessionHas('success');
            $this->travelBack();

            $sanction = Sanction::where('reason', $variant)->sole();
            $case = $this->caseOf($report);
            if ($variant === 'reclaim') {
                $this->assertSame('actioned', $case['status']);
                $this->assertTrue($this->cases()->referencesSanction($case, $sanction->id));
                $this->assertSame('confirmed', collect($case['decisions'])->firstWhere('sanction_id', $sanction->id)['state']);
                $this->assertSame([$sanction->id, 'actioned'], [$report->fresh()->sanction_id, $report->fresh()->status]);
                $this->assertNull($sanction->orphaned_from_case);
            } else {
                $this->assertSame('dismissed', $case['status']);
                $this->assertSame([$sanction->id], $case['orphaned_sanction_ids']);
                $this->assertSame($case['_id'], $sanction->fresh()->orphaned_from_case);
                $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()
                    ->assertSee('id="orphaned-sanctions"', false)->assertSee(route('admin.sanctions.show', $sanction), false);
                $this->assertSame(1, $this->service()->repair()['orphaned_total']);
            }
        }
        $this->assertSame(2, Sanction::count(), 'one sanction per request, none silent');
        $this->artisan('moderation:repair', ['--lease' => 0])->assertExitCode(1);
    }

    /** F2: a lost claim found only by repair is flagged too. */
    public function test_repair_flags_case_sanctions_that_no_decision_references(): void
    {
        $author = $this->user();
        $report = $this->report($this->user(), $this->comment($author, $this->article()));
        $caseId = (string) $report->fresh()->case_id;
        $this->actingAs($this->admin())->put(route('admin.reports.update', $report), ['status' => 'dismissed']);
        $stray = new Sanction(['user_id' => (string) $author->id, 'admin_id' => 'x', 'type' => 'warning', 'reason' => 'stray', 'is_active' => true]);
        $stray->forceFill(['case_id' => $caseId, 'audit_case_id' => $caseId])->save();

        $this->assertSame(0, $this->service()->repair()['orphaned_new'], 'too young: may still be re-claiming');
        $this->travel(2)->minutes();
        $this->assertSame(['orphaned_new' => 1, 'orphaned_total' => 1], array_intersect_key($this->service()->repair(), ['orphaned_new' => 0, 'orphaned_total' => 0]));
        $this->assertSame(0, $this->service()->repair()['orphaned_new']);
    }

    /** F4: a late sync carrying an older case version must not resurrect a decided case. */
    public function test_out_of_order_queue_sync_is_ignored_by_version(): void
    {
        $report = $this->report($this->user(), $this->comment($this->user(), $this->article()));
        $queue = app(ModerationQueue::class);
        $queue->rebuild();
        $stale = $this->caseOf($report);
        $this->assertSame(1, $queue->pendingCases());

        $this->service()->resolveCase($this->admin(), $report, 'dismissed', null);
        $this->assertSame(0, $queue->pendingCases());
        $queue->sync($stale); // the ZADD of an older request arrives after the ZREM
        $this->assertSame(0, $queue->pendingCases());
        $this->assertSame(0, Redis::connection('nosql')->zcard(ModerationQueue::KEY));
        $this->assertTrue($queue->state()['valid']);

        // Same after a rebuild: the decided case keeps a version tombstone.
        $queue->rebuild();
        $queue->sync($stale);
        $this->assertSame([0, 0], [$queue->pendingCases(), Redis::connection('nosql')->zcard(ModerationQueue::KEY)]);
    }

    /** F5: a temporary key evicted mid-rebuild is never published; reads stay on MongoDB. */
    public function test_rebuild_swap_aborts_when_the_temporary_set_is_incomplete(): void
    {
        $this->seedQueueCases();
        $queue = app(ModerationQueue::class);
        $redis = Redis::connection('nosql');
        $redis->zadd(ModerationQueue::TMP, 1, 'only-one-member-survived');
        $redis->hset(ModerationQueue::TMP_META, 'v:only-one-member-survived', 1);

        $this->assertFalse($queue->swap(4, 4));
        $this->assertSame(0, $redis->exists(ModerationQueue::META, ModerationQueue::KEY, ModerationQueue::TMP, ModerationQueue::TMP_META));
        $this->assertFalse($queue->state()['valid']);
        $this->assertSame('mongodb', $this->queueView($this->admin())[1]);
        $this->assertSame(4, $queue->rebuild(), 'a complete rebuild succeeds');
    }

    /** F6: the conditional case write is refused when another writer bumped the version after our read. */
    public function test_stale_version_conditional_case_write_is_refused(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $first = $this->report($this->user(), $comment);
        $admin = $this->admin();
        $read = $this->caseOf($first);
        $second = $this->report($this->user(), $comment); // another writer: version + 1

        $this->assertNull($this->cases()->attemptDecision($read, 'dismissed', (string) $admin->id, 'stale'));
        $case = $this->caseOf($first);
        $this->assertSame(['pending', [], $read['version'] + 1], [$case['status'], $case['decisions'], $case['version']]);
        $this->assertSame(['pending', 'pending'], [$first->fresh()->status, $second->fresh()->status]);
        // decide() re-reads and covers both reports.
        $this->assertSame(2, $this->service()->resolveCase($admin, $first, 'dismissed', 'fresh'));
    }

    /** NIT-3: a decided report never opens a round. */
    public function test_registering_a_decided_report_records_history_without_reopening(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $report = Report::create(['reporter_id' => 'legacy', 'comment_id' => $comment->id, 'reason' => 'spam', 'status' => 'dismissed']);
        $case = $this->caseOf($report);
        $this->assertSame(['none', 0, 1], [$case['status'], $case['pending_count'], $case['reports_count']]);
        $this->assertSame(0, collect($case['events'])->firstWhere('report_id', $report->id)['round']);
        $this->assertSame(0, app(ModerationQueue::class)->pendingCases());
    }

    /** F3 + NIT-5: the moderation section survives a Story overview failure (Gate 9.5 slice 5: legacy SQL warnings removed). */
    public function test_processing_page_moderation_section_survives_a_story_overview_failure(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.processing.index'))->assertOk()
            ->assertSee('Kiểm duyệt · lịch sử quyết định')->assertDontSee('id="moderation-legacy-warning"', false);

        $manager = Redis::getFacadeRoot();
        Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RuntimeException('down', 0, new \RedisException('down')));
        try {
            $this->actingAs($admin)->get(route('admin.processing.index'))->assertOk()
                ->assertSee('Tạm thời không thể tải tình trạng xử lý.')->assertSee('Kiểm duyệt · lịch sử quyết định')
                ->assertSee('Tạm thời không thể tải tình trạng luồng kiểm duyệt.')->assertDontSee('import-sql');
        } finally {
            Redis::swap($manager);
        }
    }

    public function test_duplicate_report_is_refused_by_the_unique_index_without_double_counting(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $reporter = $this->user();
        $report = $this->report($reporter, $comment);
        try {
            Report::create(['reporter_id' => (string) $reporter->id, 'comment_id' => $comment->id, 'reason' => 'spam']);
            $this->fail('Unique index must refuse a duplicate report');
        } catch (BulkWriteException $e) {
            $this->assertSame(11000, $e->getCode());
        }
        $this->assertSame(1, Report::count());
        $this->assertSame([1, 1], [$this->caseOf($report)['pending_count'], $this->caseOf($report)['reports_count']]);
    }

    public function test_appeal_decisions_and_revoke_apply_exactly_once(): void
    {
        $user = $this->user();
        $admin = $this->admin();
        $sanction = $this->sanctionFor($user, $admin, 'temporary_ban');
        $this->service()->submitAppeal($user, $sanction, 'Xin xem xét lại quyết định');
        $stale = Sanction::find($sanction->id);
        $epoch = $user->fresh()->api_token_epoch;

        $this->service()->decideAppeal($admin, $stale, 'approved', 'ok');
        try {
            $this->service()->decideAppeal($admin, $stale, 'denied', 'late');
            $this->fail('A decided appeal cannot be decided again');
        } catch (ModerationException) {
        }
        try {
            $this->service()->revokeSanction($admin, $stale, 'again');
            $this->fail('Already revoked');
        } catch (ModerationException) {
        }
        $fresh = Sanction::find($sanction->id);
        $this->assertSame('approved', $fresh->appeal_status);
        $this->assertSame('appeal', $fresh->revoked_via);
        $this->assertSame($epoch + 1, $user->fresh()->api_token_epoch, 'exactly one lift bump');
        $types = collect($fresh->events)->pluck('type')->all();
        $this->assertSame(['APPEAL_SUBMITTED', 'APPEAL_APPROVED', 'SANCTION_REVOKED'], $types);
        $this->assertSame([2, 3, 4], collect($fresh->events)->pluck('aggregate_version')->all());
    }

    // --- Redis review queue -----------------------------------------------------------------------

    private function seedQueueCases(): array
    {
        $article = $this->article();
        $this->travel(-3)->hours();
        $a = $this->comment($this->user(), $article, 'A spam oldest');
        $this->report($this->user(), $a, ['reason' => 'spam']);
        $this->travel(1)->hours();
        $b = $this->comment($this->user(), $article, 'B two reporters');
        $this->report($this->user(), $b, ['reason' => 'spam']);
        $this->report($this->user(), $b, ['reason' => 'other']);
        $this->travel(1)->hours();
        $c = $this->comment($this->user(), $article, 'C hate speech');
        $this->report($this->user(), $c, ['reason' => 'hate_speech']);
        $this->travel(30)->minutes();
        $d = $this->comment($this->user(), $article, 'D spam newest');
        $this->report($this->user(), $d, ['reason' => 'spam']);
        $this->travelBack();

        return [$b->id, $c->id, $a->id, $d->id];
    }

    private function queueView(User $admin): array
    {
        $response = $this->actingAs($admin)->get(route('admin.reports.index', ['view' => 'queue']))->assertOk();

        return [$response->viewData('cases')->getCollection()->pluck('comment_id')->all(), $response->viewData('queueSource'), $response->viewData('pendingCount')];
    }

    public function test_queue_orders_by_reporters_then_severity_then_age_and_mongo_fallback_matches(): void
    {
        $expected = $this->seedQueueCases();
        $admin = $this->admin();
        $redis = Redis::connection('nosql');

        // Never built: MongoDB answers in the same order and the queue is rebuilt lazily.
        $this->assertSame([$expected, 'mongodb', 4], $this->queueView($admin));
        $this->assertTrue(app(ModerationQueue::class)->state()['valid']);
        $this->assertSame([$expected, 'redis', 4], $this->queueView($admin));
        $ids = DB::connection('mongodb')->table('moderation_cases')->get()->keyBy('comment_id');
        $this->assertSame(array_map(fn ($cid) => (string) $ids[$cid]->id, $expected), $redis->zrevrange(ModerationQueue::KEY, 0, -1));

        // LRU eviction of either key is detected; MongoDB answers; the next read is from Redis again.
        foreach ([ModerationQueue::KEY, ModerationQueue::META] as $evicted) {
            $redis->del($evicted);
            $this->assertSame([$expected, 'mongodb', 4], $this->queueView($admin));
            $this->assertSame([$expected, 'redis', 4], $this->queueView($admin));
        }

        // Every state change updates the projection: deciding B removes it, a new report re-adds/reorders.
        $bReport = Report::where('comment_id', $expected[0])->first();
        $this->actingAs($admin)->put(route('admin.reports.update', $bReport), ['status' => 'dismissed'])->assertSessionHas('success');
        $this->assertSame([[$expected[1], $expected[2], $expected[3]], 'redis', 3], $this->queueView($admin));
        $this->report($this->user(), Comment::find($expected[3]), ['reason' => 'sexual_content']);
        $this->assertSame([[$expected[3], $expected[1], $expected[2]], 'redis', 3], $this->queueView($admin));
        $this->assertSame(3, (int) $redis->hget(ModerationQueue::META, 'count'));
    }

    public function test_rebuild_command_restores_queue_and_counters_and_redis_outage_falls_back(): void
    {
        $expected = $this->seedQueueCases();
        $admin = $this->admin();
        $appealed = $this->sanctionFor($this->user(), $admin, 'mute');
        $this->service()->submitAppeal(User::find($appealed->user_id), $appealed, 'Xin xem xét lại quyết định này');
        $redis = Redis::connection('nosql');
        $this->flushTestDatabase($redis);

        $this->artisan('moderation:rebuild-queue')->expectsOutputToContain('Queue rebuilt: 4 pending cases; pending appeals: 1')->assertExitCode(0);
        $this->assertSame(4, $redis->zcard(ModerationQueue::KEY));
        $this->assertSame('1', $redis->hget(ModerationQueue::COUNTERS, 'pending_appeals'));
        $this->actingAs($admin)->get(route('admin.sanctions.index'))->assertOk()->assertSee('1 kháng cáo');

        // Redis unreachable: inbox, queue view and badges still answer from MongoDB.
        $manager = Redis::getFacadeRoot();
        Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RuntimeException('redis down'));
        try {
            $this->assertSame([$expected, 'mongodb', 4], $this->queueView($admin));
            $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('4 vụ chờ duyệt');
            $this->actingAs($admin)->get(route('admin.sanctions.index'))->assertOk()->assertSee('1 kháng cáo');
        } finally {
            Redis::swap($manager);
        }
    }

    // --- Cassandra audit --------------------------------------------------------------------------

    public function test_audit_projection_is_idempotent_for_duplicate_delivery_replay_and_reemit(): void
    {
        $comment = $this->comment($this->user(), $this->article());
        $report = $this->report($this->user(), $comment);
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.reports.update', $report), ['status' => 'false_report', 'note' => 'Lạm dụng']);
        $caseId = (string) $report->fresh()->case_id;
        $audit = app(ModerationAudit::class);

        $this->assertSame(2, $this->consumeAll());
        $timeline = $audit->timeline($caseId);
        $this->assertSame('ready', $timeline['state']);
        $this->assertSame(['REPORTED', 'CASE_FALSE_REPORT'], array_column($timeline['events'], 'type'));
        $this->assertSame([1, 2], array_column($timeline['events'], 'case_version'));

        // Duplicate delivery of the same canonical events, then a full reemit: still one row per event.
        $canonical = $audit->canonicalFor($caseId);
        $audit->publish($canonical);
        $this->assertSame(2, $audit->reemit($caseId));
        $this->assertSame(4, $this->consumeAll());
        $this->assertCount(2, $audit->timeline($caseId)['events']);

        // A forged/altered event is never projected; it ends in the DLQ and replay keeps refusing it.
        $forged = $canonical[1];
        $forged['note'] = 'altered';
        $audit->publish([$forged]);
        $this->drainToDeadLetters();
        $this->assertSame(1, $audit->stream()->lengths()['dead_letters']);
        $this->assertCount(2, $audit->timeline($caseId)['events']);
        $this->assertSame('Lạm dụng', $audit->timeline($caseId)['events'][1]['note']);

        $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('Lịch sử quyết định')->assertSee('data-audit-state="ready"', false)->assertSee('Báo cáo sai');
    }

    public function test_cassandra_down_never_blocks_actions_and_dlq_replay_fills_the_timeline_later(): void
    {
        $author = $this->user();
        $comment = $this->comment($author, $this->article());
        $report = $this->report($this->user(), $comment);
        $admin = $this->admin();
        config(['nosql.cassandra.port' => 1]); // nothing listens: every Cassandra call fails fast

        $this->actingAs($admin)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'temporary_ban', 'reason' => 'Vi phạm', 'duration_days' => 3, 'hide_comment' => 1,
        ])->assertSessionHas('success');
        $sanction = Sanction::sole();
        $this->actingAs($author->fresh())->post(route('sanctions.appeal', $sanction), ['appeal_message' => 'Tôi bị oan, xin xem lại'])->assertSessionHas('success');
        $this->actingAs($admin)->put(route('admin.sanctions.update', $sanction), ['appeal_status' => 'approved', 'note' => 'Đồng ý'])->assertSessionHas('success');
        $this->assertFalse((bool) $comment->fresh()->is_hidden);
        $this->assertFalse($sanction->fresh()->is_active);

        $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('data-audit-state="pending"', false)->assertSee('đang chờ đồng bộ');
        $this->drainToDeadLetters();
        $dead = app(ModerationAudit::class)->stream()->deadLetters();
        $this->assertCount(7, $dead); // REPORTED, SANCTION_ISSUED, COMMENT_HIDDEN, APPEAL_SUBMITTED, APPEAL_APPROVED, SANCTION_REVOKED, COMMENT_UNHIDDEN
        $this->actingAs($admin)->get(route('admin.processing.index'))->assertOk()->assertSee('Kiểm duyệt · lịch sử quyết định')->assertSee('DLQ: 7');

        config(['nosql.cassandra.port' => $this->cassandraPort]);
        foreach (array_keys($dead) as $id) {
            $this->actingAs($admin)->post(route('admin.processing.retry'), ['section' => 'moderation', 'id' => $id])->assertSessionHas('success');
        }
        $this->assertSame(7, $this->consumeAll());
        $timeline = app(ModerationAudit::class)->timeline((string) $report->fresh()->case_id);
        $this->assertSame('ready', $timeline['state']);
        $this->assertSame(['REPORTED', 'SANCTION_ISSUED', 'COMMENT_HIDDEN', 'APPEAL_SUBMITTED', 'APPEAL_APPROVED', 'SANCTION_REVOKED', 'COMMENT_UNHIDDEN'],
            array_column($timeline['events'], 'type'));
        $this->assertSame((string) $admin->id, $timeline['events'][1]['actor_id']);
        // NIT-2: a case-linked sanction event carries the sanction's own version and the case version separately.
        $this->assertSame(['sanction', 1], [$timeline['events'][1]['aggregate_type'], $timeline['events'][1]['aggregate_version']]);
        $this->assertGreaterThan(1, $timeline['events'][1]['case_version']);
        $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()->assertSee('data-audit-state="ready"', false)
            ->assertSee('Chấp nhận kháng cáo')->assertSee('Hiện lại bình luận');
    }

    public function test_sanction_without_case_uses_its_own_id_as_audit_partition(): void
    {
        $user = $this->user();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.sanctions.store'), ['user_id' => (string) $user->id, 'type' => 'mute', 'reason' => 'Gây rối', 'duration_days' => 2])
            ->assertSessionHas('success');
        $sanction = Sanction::sole();
        $this->assertSame($sanction->id, $sanction->audit_case_id);
        $this->assertNull($sanction->case_id);
        $this->actingAs($admin)->post(route('admin.sanctions.revoke', $sanction), ['note' => 'Nhầm'])->assertSessionHas('success');

        $this->consumeAll();
        $timeline = app(ModerationAudit::class)->timeline($sanction->id);
        $this->assertSame(['SANCTION_ISSUED', 'SANCTION_REVOKED'], array_column($timeline['events'], 'type'));
        $this->actingAs($admin)->get(route('admin.sanctions.show', $sanction))->assertOk()->assertSee('data-audit-state="ready"', false)->assertSee('Nhầm');
        $this->artisan('moderation:audit', ['action' => 'timeline', '--case' => $sanction->id])->expectsOutputToContain('SANCTION_REVOKED')->assertExitCode(0);
    }

    // --- Data move ------------------------------------------------------------------------------

    // --- API-token hooks ----------------------------------------------------------------------------

    public function test_ban_from_case_revoke_and_appeal_keep_token_hooks_and_order(): void
    {
        $author = $this->user();
        $admin = $this->admin();
        $report = $this->report($this->user(), $this->comment($author, $this->article()));
        $tokens = app(ApiTokenService::class);
        $pair = $tokens->issue($author->fresh());

        $this->actingAs($admin)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'permanent_ban', 'reason' => 'x',
        ])->assertSessionHas('success');
        $this->assertSame(2, $author->fresh()->api_token_epoch, 'pre-write and post-write bumps');
        $this->assertSame([], Redis::connection('nosql')->zrange('api-token:user:'.$author->id, 0, -1));
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$pair['access_token']])->assertUnauthorized();

        $this->actingAs($admin)->post(route('admin.sanctions.revoke', Sanction::sole()))->assertSessionHas('success');
        $this->assertSame(3, $author->fresh()->api_token_epoch, 'lift bump after the Mongo write');
        $this->assertFalse($author->fresh()->isBanned());
        $this->actingAs($admin)->post(route('admin.sanctions.revoke', Sanction::sole()))->assertSessionHas('error');
        $this->assertSame(3, $author->fresh()->api_token_epoch, 'a refused revoke does not bump');
    }
}
