<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Moderation\ModerationAudit;
use App\Moderation\ModerationCases;
use App\Moderation\ModerationService;
use App\StoryProcessing\CassandraClient;
use Cassandra\Value\Bigint;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use Database\Seeders\DemoEngagementSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use MongoDB\Collection;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

/**
 * Gate 9.5 slice 2: comments on MongoDB. Isolated stores only: Mongo vietfeed_test, Redis DB15 (guard in
 * ModerationFixtures), Cassandra keyspace vietfeed_test. Gate 9.5 slice 5: no SQL connection exists; the legacy
 * comment import was removed (tag `pre-sql-removal`).
 */
class CommentsMongoTest extends TestCase
{
    use ModerationFixtures;

    private const UUID7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<string> extra Cassandra partitions written directly by a test */
    private array $partitions = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.moderation_retry_ms' => 1]);
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        app(ModerationAudit::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
            $partitions = DB::connection('mongodb')->table('moderation_cases')->pluck('_id')
                ->merge(DB::connection('mongodb')->table('sanctions')->pluck('audit_case_id'))->merge($this->partitions)->filter()->unique();
            foreach ($partitions as $id) {
                app(CassandraClient::class)->execute('DELETE FROM vietfeed_test.moderation_events_by_case WHERE case_id = ?', [new Uuid((string) $id)]);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function consumeAll(): void
    {
        while (app(ModerationAudit::class)->consume('test', 100) > 0) {
        }
    }

    private function rows(string $caseId): array
    {
        return array_map(fn ($row) => (array) $row, app(CassandraClient::class)->rows(
            'SELECT type, comment_id, comment_ref FROM vietfeed_test.moderation_events_by_case WHERE case_id = ?', [new Uuid($caseId)]));
    }

    private function raw(string $collection): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($collection, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    public function test_comments_and_replies_are_mongo_documents_with_uuid7_ids_and_string_references(): void
    {
        $article = $this->article();
        $author = $this->user();
        $this->actingAs($author)->post(route('comments.store', $article->slug), ['body' => 'Bình luận gốc'])->assertSessionHas('success');
        $root = Comment::sole();
        $this->actingAs($author)->post(route('comments.store', $article->slug), ['body' => 'Trả lời', 'parent_id' => $root->id])->assertSessionHas('success');
        $reply = Comment::where('body', 'Trả lời')->sole();

        $this->assertSame('mongodb', $root->getConnectionName());
        $this->assertMatchesRegularExpression(self::UUID7, $root->id);
        $doc = $this->raw('comments')->findOne(['_id' => $reply->id]);
        $this->assertSame([(string) $author->id, (string) $article->id, $root->id, false, null],
            [$doc['user_id'], $doc['article_id'], $doc['parent_id'], $doc['is_hidden'], $doc['hidden_by_sanction_id']]);
        $this->assertNull($this->raw('comments')->findOne(['_id' => $root->id])['parent_id']);

        // Unknown parent (valid UUID, absent) is refused like the former SQL exists rule.
        $this->actingAs($author)->post(route('comments.store', $article->slug), ['body' => 'Lạc', 'parent_id' => (string) Str::uuid7()])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($author)->put(route('comments.update', $root), ['body' => 'Đã sửa'])->assertSessionHas('success');
        $this->assertSame('Đã sửa', $root->fresh()->body);
        $this->actingAs($this->user())->put(route('comments.update', $root), ['body' => 'Không phải của tôi'])->assertForbidden();
        $this->actingAs($author)->put('/comments/12345', ['body' => 'x'])->assertNotFound();

        // Article page: root with its reply, author names, one sanction read (9a R1 is covered elsewhere).
        $this->get('/articles/'.$article->slug)->assertOk()->assertSeeInOrder(['Đã sửa', 'Trả lời']);
    }

    public function test_deleting_a_comment_deletes_its_replies_at_any_depth_and_keeps_reports(): void
    {
        $article = $this->article();
        $author = $this->user();
        $root = $this->comment($author, $article, 'Gốc');
        $reply = $this->comment($this->user(), $article, 'Trả lời cấp 1', $root->id);
        $deep = $this->comment($this->user(), $article, 'Trả lời cấp 2', $reply->id);
        $sibling = $this->comment($author, $article, 'Bình luận khác');
        $siblingReply = $this->comment($this->user(), $article, 'Trả lời bình luận khác', $sibling->id);
        $report = $this->report($this->user(), $deep);

        $this->actingAs($author)->delete(route('comments.destroy', $root))->assertSessionHas('success');

        $this->assertSame([], Comment::whereIn('_id', [$root->id, $reply->id, $deep->id])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$sibling->id, $siblingReply->id], Comment::pluck('id')->all());
        $this->assertSame('Trả lời cấp 2', $report->fresh()->comment_body);
        $this->assertSame($deep->id, $report->fresh()->comment_id);
        $this->actingAs($this->admin())->get(route('admin.reports.show', $report))->assertOk()->assertSee('Bình luận đã bị xóa');

        // Admin delete takes the same model path.
        $this->actingAs($this->admin())->delete(route('admin.comments.destroy', $sibling))->assertSessionHas('success');
        $this->assertSame(0, Comment::count());
        $this->assertSame(1, Report::count());
    }

    public function test_hide_and_unhide_are_conditional_on_the_comment_document(): void
    {
        $admin = $this->admin();
        $author = $this->user();
        $article = $this->article();
        $service = app(ModerationService::class);

        // Hide from a case: is_hidden:false → hidden by S1.
        $first = $this->comment($author, $article, 'Ẩn bởi S1');
        $s1 = $service->issueSanction($admin, $author, 'warning', 'Lý do 1', null, $this->report($this->user(), $first)->id, true);
        $doc = $this->raw('comments')->findOne(['_id' => $first->id]);
        $this->assertSame([true, (string) $s1->id], [$doc['is_hidden'], $doc['hidden_by_sanction_id']]);

        // Another sanction already owns the hide: a later hide request is skipped (no takeover, R4).
        $second = $this->comment($author, $article, 'Đã bị ẩn trước');
        $pending = $this->report($this->user(), $second);
        $owner = $this->sanctionFor($author, $admin, 'warning');
        $this->raw('comments')->updateOne(['_id' => $second->id], ['$set' => ['is_hidden' => true, 'hidden_by_sanction_id' => (string) $owner->id]]);
        $s2 = $service->issueSanction($admin, $author, 'warning', 'Lý do 2', null, $pending->id, true);
        $this->assertSame((string) $owner->id, $second->fresh()->hidden_by_sanction_id);
        $decision = collect(app(ModerationCases::class)->find((string) $pending->fresh()->case_id)['decisions'])->firstWhere('sanction_id', (string) $s2->id);
        $this->assertSame('skipped', $decision['hide_state']);
        $this->assertNotContains('COMMENT_HIDDEN', collect($s2->fresh()->events)->pluck('type')->all());

        // Unhide only where hidden_by_sanction_id = S: revoking S2 leaves the owner's hide in place,
        // revoking S1 unhides only its own comment, once.
        $service->revokeSanction($admin, $s2, 'thu hồi S2');
        $this->assertTrue($second->fresh()->is_hidden);
        $this->assertNotContains('COMMENT_UNHIDDEN', collect($s2->fresh()->events)->pluck('type')->all());
        $service->revokeSanction($admin, $s1, 'thu hồi S1');
        $this->assertSame([false, null], [$first->fresh()->is_hidden, $first->fresh()->hidden_by_sanction_id]);
        $this->assertTrue($second->fresh()->is_hidden);
        $unhidden = collect($s1->fresh()->events)->where('type', 'COMMENT_UNHIDDEN')->values();
        $this->assertCount(1, $unhidden);
        $this->assertSame($first->id, $unhidden[0]['comment_id']);

        // F1 sweep: a comment left hidden by a revoked sanction (crash) is unhidden by repair, string ids end to end.
        $this->raw('comments')->updateOne(['_id' => $first->id], ['$set' => ['is_hidden' => true, 'hidden_by_sanction_id' => (string) $s1->id]]);
        $this->assertSame(1, $service->repair()['unhidden_revoked']);
        $this->assertFalse($first->fresh()->is_hidden);
        $this->assertSame(0, $service->repair()['unhidden_revoked']);

        // Fillable guard still holds on Mongo: hiding is only possible through the moderation path.
        $third = $this->comment($author, $article, 'Không thể tự ẩn');
        $third->update(['is_hidden' => true, 'hidden_by_sanction_id' => 'x']);
        $this->assertSame([false, null], [$third->fresh()->is_hidden, $third->fresh()->hidden_by_sanction_id]);
    }

    public function test_string_comment_id_flows_from_report_to_case_events_and_the_cassandra_comment_ref_column(): void
    {
        $admin = $this->admin();
        $author = $this->user();
        $comment = $this->comment($author, $this->article(), 'Tham chiếu chuỗi');
        $report = $this->report($this->user(), $comment);
        $this->actingAs($admin)->post(route('admin.sanctions.store'), [
            'user_id' => (string) $author->id, 'report_id' => $report->id, 'type' => 'warning', 'reason' => 'x', 'hide_comment' => 1,
        ])->assertSessionHas('success');

        $reportDoc = $this->raw('reports')->findOne(['_id' => $report->id]);
        $case = app(ModerationCases::class)->find((string) $reportDoc['case_id']);
        $this->assertSame($comment->id, $reportDoc['comment_id']);
        $this->assertSame($case['_id'], app(ModerationCases::class)->findByComment($comment->id)['_id']);
        $this->assertSame([$comment->id], collect($case['events'])->pluck('comment_id')->unique()->values()->all());
        $sanction = Sanction::sole();
        $this->assertSame([$comment->id], collect($sanction->events)->pluck('comment_id')->filter()->unique()->values()->all());
        $this->assertTrue($comment->fresh()->is_hidden);

        $this->consumeAll();
        $rows = $this->rows((string) $case['_id']);
        $this->assertSame(['REPORTED', 'SANCTION_ISSUED', 'COMMENT_HIDDEN'], array_column($rows, 'type'));
        foreach ($rows as $row) {
            $this->assertSame($comment->id, $row['comment_ref']);
            $this->assertNull($row['comment_id'], 'new events never write the legacy bigint column');
        }
        $timeline = app(ModerationAudit::class)->timeline((string) $case['_id']);
        $this->assertSame('ready', $timeline['state']);
        $this->assertSame([$comment->id], array_values(array_unique(array_column($timeline['events'], 'comment'))));
        $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk()->assertSee('data-comment="'.$comment->id.'"', false);
    }

    public function test_timeline_reads_legacy_bigint_rows_next_to_comment_ref_rows_and_schema_bootstrap_is_idempotent(): void
    {
        app(ModerationAudit::class)->bootstrap(); // second ALTER … ADD comment_ref is tolerated
        $caseId = (string) Str::uuid7();
        $this->partitions[] = $caseId;
        $insert = 'INSERT INTO vietfeed_test.moderation_events_by_case (case_id, occurred_at, event_id, type, aggregate_version, aggregate_type, aggregate_id, ';
        app(CassandraClient::class)->execute($insert.'comment_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [new Uuid($caseId), new Timestamp('2026-11-01T00:00:00.000Z'),
            new Uuid((string) Str::uuid7()), 'REPORTED', new Bigint(1), 'case', $caseId, new Bigint(42)]);
        $ref = (string) Str::uuid7();
        app(CassandraClient::class)->execute($insert.'comment_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [new Uuid($caseId), new Timestamp('2026-11-02T00:00:00.000Z'),
            new Uuid((string) Str::uuid7()), 'CASE_DISMISSED', new Bigint(2), 'case', $caseId, $ref]);

        $events = app(ModerationAudit::class)->timeline($caseId)['events'];

        $this->assertSame(['42', $ref], array_column($events, 'comment'));
    }

    /** REVIEW-1 m1: only "column already exists" is tolerated by the schema ALTERs. */
    public function test_cassandra_schema_alter_only_tolerates_an_existing_column(): void
    {
        $existing = new \RuntimeException('Cassandra request failed (ServerException)', 0,
            new \RuntimeException('Invalid column name comment_ref because it conflicts with an existing column'));
        ModerationAudit::addColumn(fn () => throw $existing);

        // A real Cassandra error that is not "already exists" (unknown table) is rethrown.
        $keyspace = app(CassandraClient::class)->keyspace();
        $this->assertSame('vietfeed_test', $keyspace);
        try {
            ModerationAudit::addColumn(fn () => app(CassandraClient::class)->execute('ALTER TABLE vietfeed_test.no_such_table_g95 ADD comment_ref text'));
            $this->fail('an unrelated ALTER error must be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cassandra request failed', $e->getMessage());
        }

        // A failing client (nothing listens): schema bootstrap reports the failure instead of success.
        $port = config('nosql.cassandra.port');
        config(['nosql.cassandra.port' => 1]);
        try {
            $this->expectException(\RuntimeException::class);
            (new ModerationAudit(new CassandraClient))->bootstrap();
        } finally {
            config(['nosql.cassandra.port' => $port]);
        }
    }

    public function test_no_code_reads_sql_comments(): void
    {
        // Source proof (Gate 9.5 slice 5: the import classes were removed): no SQL access to a `comments` table remains.
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/(DB::table|->table)\(\s*[\'"]comments[\'"]|exists:comments\b|FROM\s+comments\b/i', file_get_contents($file->getPathname()))) {
                $hits[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        sort($hits);
        $this->assertSame([], $hits);
        $this->assertSame('mongodb', (new Comment)->getConnectionName());

        // Runtime proof: no SQL statement touches `comments` on any comment, moderation or count page.
        $admin = $this->admin();
        $author = $this->user();
        $article = $this->article();
        $comment = $this->comment($author, $article, 'Không chạm SQL');
        $this->comment($this->user(), $article, 'Trả lời', $comment->id);
        $sql = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql) {
            if ($q->connectionName !== 'mongodb') {
                $sql[] = $q->sql;
            }
        });
        $report = $this->report($this->user(), $comment);
        $this->actingAs($author)->post(route('comments.store', $article->slug), ['body' => 'Mới', 'parent_id' => $comment->id])->assertSessionHas('success');
        $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'Đã sửa'])->assertSessionHas('success');
        $this->get('/')->assertOk();
        $this->get('/articles/'.$article->slug)->assertOk();
        foreach (['admin.dashboard', 'admin.comments.index', 'admin.users.index', 'admin.reports.index'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.articles.show', $article))->assertOk()->assertSee('Đã sửa');
        $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk();
        $this->actingAs($admin)->post(route('admin.sanctions.store'), ['user_id' => (string) $author->id, 'report_id' => $report->id,
            'type' => 'warning', 'reason' => 'x', 'hide_comment' => 1])->assertSessionHas('success');
        $this->actingAs($admin)->delete(route('admin.comments.destroy', $comment))->assertSessionHas('success');

        // Gate 9.5 slice 5: no SQL connection is configured, so no page issues any SQL statement at all.
        $this->assertSame([], $sql);
        $this->assertSame(['mongodb'], array_keys(DB::getConnections()));
    }

    public function test_counts_are_one_grouped_mongo_query_per_page(): void
    {
        $article = $this->article();
        $a = $this->user(['name' => 'Người A']);
        $b = $this->user(['name' => 'Người B']);
        $this->comment($a, $article, 'A1');
        $this->comment($a, $article, 'A2');
        $this->comment($b, $article, 'B1');
        $admin = $this->admin();

        $aggregates = 0;
        DB::connection('mongodb')->enableQueryLog();
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$aggregates) {
            if ($q->connectionName === 'mongodb' && str_contains($q->sql, 'comments') && str_contains($q->sql, 'aggregate')) {
                $aggregates++;
            }
        });
        $users = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->viewData('users')->getCollection()->keyBy('id');

        $this->assertSame([2, 1, 0], [$users[$a->id]->comments_count, $users[$b->id]->comments_count, $users[$admin->id]->comments_count]);
        $this->assertSame(1, $aggregates, 'one grouped comment count for the whole page');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['comments'] === 3);
    }

    public function test_demo_engagement_seeder_writes_mongo_comments_and_a_reply_idempotently(): void
    {
        $article = $this->article();
        $first = $this->user(['email' => 'user1@vietfeed.com']);
        $this->user(['email' => 'user2@vietfeed.com']);

        $this->seed(DemoEngagementSeeder::class);
        $this->seed(DemoEngagementSeeder::class);

        $root = Comment::whereNull('parent_id')->sole();
        $this->assertSame([(string) $first->id, (string) $article->id], [$root->user_id, $root->article_id]);
        $this->assertSame($root->id, Comment::whereNotNull('parent_id')->sole()->parent_id);
        $this->assertSame(2, Comment::count());
    }

    public function test_comment_index_migration_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_18_200001_create_mongodb_comment_indexes.php');
        $migration->up();
        $migration->up();

        $indexes = collect(iterator_to_array(DB::connection('mongodb')->getCollection('comments')->listIndexes()))
            ->mapWithKeys(fn ($index) => [$index->getName() => $index]);
        foreach (['article_id_1_created_at_-1', 'parent_id_1', 'user_id_1', 'hidden_by_sanction_id_1', 'legacy_sql_id_1'] as $name) {
            $this->assertTrue($indexes->has($name), $name);
        }
        $this->assertTrue($indexes['legacy_sql_id_1']->isUnique());
        // REVIEW-1 n1: only real sanction ids are indexed (visible comments store an explicit null) and the
        // unhide lookup by sanction id uses that index.
        $this->assertEquals(['hidden_by_sanction_id' => ['$gt' => '']],
            json_decode(json_encode($indexes['hidden_by_sanction_id_1']['partialFilterExpression']), true));
        $explain = DB::connection('mongodb')->getMongoDB()->command(['explain' => ['find' => 'comments', 'filter' => ['hidden_by_sanction_id' => (string) Str::uuid7()]]],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])->toArray()[0];
        $this->assertStringContainsString('hidden_by_sanction_id_1', json_encode($explain['queryPlanner']['winningPlan']));
    }
}
