<?php

namespace Tests\Feature;

use App\Accounts\AccountLifecycle;
use App\Accounts\AccountPurge;
use App\ApiTokens\ApiTokenService;
use App\Authorization\Permissions;
use App\Authorization\SuperAdminGuard;
use App\Billing\BillingGateway;
use App\Billing\StripeClient;
use App\Interaction\InteractionStream;
use App\Interaction\InteractionToggle;
use App\Interaction\Trending;
use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Comment;
use App\Models\Sanction;
use App\Models\User;
use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\StoryProcessing\CassandraClient;
use App\StoryProcessing\Neo4jClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Laudis\Neo4j\Types\CypherList;
use Mockery\MockInterface;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Doubles\FakeStripeClient;
use Tests\TestCase;

/** Gate 9.7b slice A: one test per real risk of the deletion lifecycle, on the isolated Mongo/Cassandra/Redis stores. */
class AccountLifecycleTest extends TestCase
{
    private FakeStripeClient $stripe;

    /** @var list<string> user ids whose Cassandra partitions must not outlive the test */
    private array $cassandraUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        config(['services.stripe.webhook_secret' => 'whsec_gate97b_only', 'services.stripe.pro_price_id' => 'price_gate97b']);
        $this->app->instance(StripeClient::class, $this->stripe = new FakeStripeClient);
    }

    protected function tearDown(): void
    {
        try {
            $client = app(CassandraClient::class);
            $keyspace = $client->keyspace();
            foreach ($this->cassandraUsers as $id) {
                foreach (['activity_by_user_month', 'unlocks_by_user_month'] as $table) {
                    $client->execute("DELETE FROM $keyspace.$table WHERE user_id = ? AND year_month = ?", [$id, now('UTC')->format('Y-m')]);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_request_revokes_web_sessions_api_tokens_reset_token_and_quota_immediately(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-me']);
        $pair = app(ApiTokenService::class)->issue($user);
        $resetToken = Password::broker()->createToken($user);
        Redis::connection('nosql')->client()->set(ReadingPassQuota::windowKey('u:'.$user->id), 'x');

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertGuest();

        $user = $user->fresh();
        $this->assertSame(AccountLifecycle::PENDING, $user->deletion['state']);
        $this->assertSame('self', $user->deletion['origin']);
        $this->assertNotSame('remember-me', $user->getRawOriginal('remember_token'), 'the remember cookie no longer matches');
        $this->assertFalse(Password::broker()->tokenExists($user, $resetToken));
        $this->assertSame(0, Redis::connection('nosql')->client()->exists(ReadingPassQuota::windowKey('u:'.$user->id)));
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$pair['access_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->assertSame(1, DB::connection('mongodb')->table(AccountLifecycle::WORKFLOWS)->where('state', 'pending')->count());

        // A session created before the request dies on its next request; signing in only opens the recovery page.
        $this->withSession([Auth::guard('web')->getName() => $user->id]);
        Auth::forgetGuards();
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.recover'));
        $this->get(route('profile.edit'))->assertRedirect(route('account.recover'));
        $this->get(route('account.recover'))->assertOk()->assertSee('Khôi phục tài khoản');
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_staff_cannot_request_deletion_and_a_pending_account_cannot_be_promoted(): void
    {
        $super = $this->superAdmin();
        $other = $this->superAdmin();
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::USERS]]);
        $plain = User::factory()->create();
        $refusal = 'Hãy gỡ quyền quản trị trước khi xóa tài khoản.';

        $this->actingAs($admin)->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertConflict()->assertJsonPath('message', $refusal);
        $this->actingAs($super)->deleteJson(route('admin.users.destroy', $admin))->assertConflict();
        $this->actingAs($other)->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertConflict();
        $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $super))->assertForbidden();
        $this->assertNull($admin->fresh()->getAttribute('deletion'));
        $this->assertNull($super->fresh()->getAttribute('deletion'));
        $this->assertSame(0, DB::connection('mongodb')->table(AccountLifecycle::WORKFLOWS)->count());

        // A plain user can be scheduled; promoting a user who is pending would let a purge delete staff.
        $this->actingAs($super)->delete(route('admin.users.destroy', $plain))->assertRedirect();
        $this->actingAs($super)->patchJson(route('admin.users.update', $plain), ['name' => $plain->name, 'email' => $plain->email, 'role' => 'admin', 'permissions' => []])->assertConflict();
        $this->assertTrue($plain->fresh()->isUser());
    }

    public function test_recovery_works_inside_the_window_and_is_refused_once_purging_or_expired(): void
    {
        $user = User::factory()->create();
        $lifecycle = app(AccountLifecycle::class);
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.recover'));
        $this->post(route('account.recover.store'))->assertRedirect(route('home'));
        $this->assertNull($user->fresh()->getAttribute('deletion'));
        $this->assertSame(0, DB::connection('mongodb')->table(AccountLifecycle::WORKFLOWS)->count());
        Auth::forgetGuards(); // the test client keeps the guard's user model between requests
        $this->get(route('profile.edit'))->assertOk();

        $lifecycle->request($user->fresh(), $user->fresh(), AccountLifecycle::ORIGIN_SELF);
        $this->assertSame(AccountLifecycle::PENDING, $user->fresh()->deletion['state']);

        $this->users()->updateOne(['_id' => AccountLifecycle::userKey($user->id)], ['$set' => ['deletion.purge_after' => new UTCDateTime(now()->subMinute())]]);
        $this->expectRefusal(fn () => $lifecycle->recover($user->id), 'window closed');
        $this->users()->updateOne(['_id' => AccountLifecycle::userKey($user->id)], ['$set' => ['deletion.purge_after' => new UTCDateTime(now()->addDay()), 'deletion.state' => AccountLifecycle::PURGING]]);
        $this->expectRefusal(fn () => $lifecycle->recover($user->id), 'purging');
        $this->assertSame(AccountLifecycle::PURGING, $user->fresh()->deletion['state']);
    }

    public function test_admin_origin_deletion_is_recoverable_only_by_an_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::USERS]]);
        $user = User::factory()->create();
        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('Lên lịch xóa');
        $this->delete(route('admin.users.destroy', $user))->assertRedirect();
        $this->assertSame('admin', $user->fresh()->deletion['origin']);
        $this->get(route('admin.users.edit', $user))->assertOk()->assertSee('Khôi phục');

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.recover'));
        $this->get(route('account.recover'))->assertOk()->assertDontSee(route('account.recover.store'), false)->assertSee('Chỉ quản trị viên');
        $this->post(route('account.recover.store'))->assertSessionHas('error');
        $this->assertSame(AccountLifecycle::PENDING, $user->fresh()->deletion['state']);

        $this->actingAs(User::factory()->create())->post(route('admin.users.recover', $user))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.users.recover', $user))->assertRedirect();
        $this->assertNull($user->fresh()->getAttribute('deletion'));
    }

    public function test_purge_removes_or_anonymizes_every_store_and_keeps_evidence(): void
    {
        $this->cassandraSchema();
        [$user, $other, $article] = $this->populatedAccount();
        $id = (string) $user->id;
        $this->mockNeo4j(1);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertContains(['cancel', 'sub_gate97b', 'vietfeed-delete-cancel-sub_gate97b'], $this->stripe->calls);
        $this->makeDue($id);

        // Dry run reports counts only and changes nothing.
        $this->artisan('accounts:purge', ['--dry-run' => true])->expectsOutputToContain('bookmarks')->doesntExpectOutputToContain($user->email)->assertSuccessful();
        $this->assertNotNull(User::find($id));
        $this->assertSame(1, Bookmark::where('user_id', $id)->count());

        $this->artisan('accounts:purge')->assertSuccessful();

        $this->assertNull(User::find($id));
        $workflow = (array) $this->workflows()->findOne(['_id' => $id]);
        $this->assertSame(['_id', 'purged_at', 'requested_at', 'state', 'steps'], collect(array_keys($workflow))->sort()->values()->all());
        $this->assertSame(AccountLifecycle::PURGED, $workflow['state']);
        $this->assertEqualsCanonicalizing([...AccountPurge::STEPS, 'stripe_cancel'], array_keys((array) $workflow['steps']->getArrayCopy()));
        $this->assertStringNotContainsString($user->email, json_encode($workflow));

        $this->assertSame([0, 0, 0], [Bookmark::where('user_id', $id)->count(), Boost::where('user_id', $id)->count(), DB::connection('mongodb')->table('article_unlocks')->where('user_id', $id)->count()]);
        $this->assertSame(1, Bookmark::where('user_id', $other->id)->count(), 'other users keep their data');
        $this->assertSame([], app(Trending::class)->top(), 'the purged boost no longer scores');
        $anonymized = Comment::findOrFail($this->commentId);
        $this->assertNull($anonymized->user_id);
        $this->assertSame('', $anonymized->body);
        $this->assertTrue($anonymized->account_deleted);
        // A guest must not see "Của bạn" or owner actions on an anonymized comment (null user_id vs null auth id).
        $this->blade('<x-comment :comment="$c" :article="$a" />', ['c' => $anonymized, 'a' => $article])
            ->assertSee('Người dùng đã xóa')->assertDontSee('Của bạn')->assertDontSee('edit-comment-'.$anonymized->id, false);
        $reply = Comment::findOrFail($this->replyId);
        $this->assertSame([(string) $other->id, 'Trả lời của người khác'], [$reply->user_id, $reply->body], 'replies by other users are untouched');
        $this->assertSame($this->commentId, $reply->parent_id);

        $this->assertSame([], $this->rows('activity_by_user_month', 'user_id', $id, 'year_month', now('UTC')->format('Y-m')));
        $this->assertSame([], $this->rows('unlocks_by_user_month', 'user_id', $id, 'year_month', now('UTC')->format('Y-m')));
        $day = $this->rows('activity_by_article_day', 'article_id', (string) $article->id, 'activity_day', now('UTC')->format('Y-m-d'));
        $this->assertNotEmpty($day);
        $this->assertEqualsCanonicalizing(['deleted', (string) $other->id], array_values(array_unique(array_column($day, 'user_id'))), 'article history stays, without the user');
        $this->assertSame(0, Redis::connection('nosql')->client()->exists(ReadingPassQuota::windowKey('u:'.$id)));

        $this->assertSame(1, Sanction::where('user_id', $id)->count(), 'sanctions are evidence');
        $this->assertSame(1, DB::connection('mongodb')->table('reports')->where('reporter_id', $id)->count());
        $this->assertSame(1, DB::connection('mongodb')->table('moderation_cases')->where('comment_user_id', $id)->count());
        $subscription = (array) DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_gate97b')->first();
        $this->assertSame(AccountLifecycle::pseudonym($id), $subscription['user_id']);
        $this->assertSame([], app(BillingGateway::class)->proUserIds([$id]));
        $this->assertContains(['update_customer', 'cus_gate97b', ['email' => '', 'name' => '', 'metadata' => ['user_id' => '']]], $this->stripe->calls);

        // Nothing is left to do, and a second run does not repeat any external call.
        $calls = count($this->stripe->calls);
        $this->artisan('accounts:purge')->assertSuccessful();
        $this->assertCount($calls, $this->stripe->calls);
    }

    public function test_purge_rerun_after_a_crash_mid_steps_is_idempotent(): void
    {
        $this->cassandraSchema();
        [$user, , $article] = $this->populatedAccount();
        $id = (string) $user->id;
        $failing = true;
        $this->mock(Neo4jClient::class, function (MockInterface $mock) use (&$failing, $id): void {
            $mock->shouldReceive('run')->twice()->andReturnUsing(function (string $query, array $params) use (&$failing, $id) {
                $this->assertSame(['id' => $id], $params);
                if ($failing) {
                    $failing = false;
                    throw new \RuntimeException('Neo4j down');
                }

                return new CypherList([]);
            });
        });
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->makeDue($id);

        // A crash right after the claim flipped the user leaves an old pending workflow: not an orphan, it must still be purged.
        $this->users()->updateOne(['_id' => AccountLifecycle::userKey($id)], ['$set' => ['deletion.state' => AccountLifecycle::PURGING]]);
        $this->workflows()->updateOne(['_id' => $id], ['$set' => ['requested_at' => new UTCDateTime(now()->subHour())], '$unset' => ['steps.stripe_cancel' => '']]);

        $this->artisan('accounts:purge')->assertFailed();
        $workflow = (array) $this->workflows()->findOne(['_id' => $id]);
        $this->assertSame(AccountLifecycle::PURGING, $workflow['state']);
        $this->assertArrayHasKey('cassandra_delete', $workflow['steps']->getArrayCopy());
        $this->assertArrayNotHasKey('neo4j', $workflow['steps']->getArrayCopy());
        $this->assertSame(AccountLifecycle::PURGING, User::find($id)->deletion['state'], 'the user document stays until the last steps');
        $this->assertNull($this->workflows()->findOne(['_id' => $id])['claim'] ?? null, 'the failed run released its lease');
        $this->expectRefusal(fn () => app(AccountLifecycle::class)->recover($id), 'purging');

        // Simulate a crash that lost four checkpoints: those steps run again on already purged data.
        $this->workflows()->updateOne(['_id' => $id], ['$unset' => ['steps.boosts' => '', 'steps.comments' => '', 'steps.cassandra_anonymize' => '', 'steps.cassandra_delete' => '']]);
        $this->artisan('accounts:purge')->assertSuccessful();

        $this->assertNull(User::find($id));
        $this->assertSame(AccountLifecycle::PURGED, $this->workflows()->findOne(['_id' => $id])['state']);
        $this->assertSame([], $this->rows('activity_by_user_month', 'user_id', $id, 'year_month', now('UTC')->format('Y-m')));
        $day = $this->rows('activity_by_article_day', 'article_id', (string) $article->id, 'activity_day', now('UTC')->format('Y-m-d'));
        $this->assertNotContains($id, array_column($day, 'user_id'));
        $this->assertContains('deleted', array_column($day, 'user_id'));
        $this->assertCount(1, array_filter($this->stripe->calls, fn ($call) => $call[0] === 'cancel'), 'Stripe is canceled once');
    }

    public function test_stripe_down_at_request_is_retried_by_the_worker_with_a_stable_key(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_gate97b']);
        $this->subscription($user);
        $this->stripe->down = true;

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertSame(AccountLifecycle::PENDING, $user->fresh()->deletion['state'], 'the request does not fail when Stripe is down');
        $this->assertArrayNotHasKey('stripe_cancel', $this->workflows()->findOne(['_id' => $user->id])['steps']->getArrayCopy());

        $this->artisan('accounts:purge')->assertFailed();
        $this->stripe->down = false;
        $this->artisan('accounts:purge')->assertSuccessful();
        $this->assertArrayHasKey('stripe_cancel', $this->workflows()->findOne(['_id' => $user->id])['steps']->getArrayCopy());
        $cancels = array_values(array_filter($this->stripe->calls, fn ($call) => $call[0] === 'cancel' && ! $this->stripe->down));
        $this->assertSame('vietfeed-delete-cancel-sub_gate97b', end($cancels)[2]);

        $before = count($this->stripe->calls);
        $this->artisan('accounts:purge')->assertSuccessful();
        $this->assertCount($before, $this->stripe->calls, 'a finished step is not repeated');
        $this->assertNotNull(User::find($user->id), 'not due yet: nothing is purged');
    }

    public function test_billing_webhooks_keep_reconciling_for_pending_and_purged_accounts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);
        $event = fn (string $id, string $status) => ['id' => $id, 'object' => 'event', 'created' => 100, 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_webhook', 'object' => 'subscription', 'customer' => 'cus_webhook', 'status' => $status,
            'metadata' => ['user_id' => $user->id, 'type' => 'pro'],
            'items' => ['data' => [['price' => ['id' => 'price_gate97b'], 'current_period_end' => 200]]],
            'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null,
        ]]];

        // A subscription that turns billable for a pending account is canceled again.
        $this->stripe->subscription = $event('evt_a', 'active')['data']['object'];
        $this->deliver($event('evt_a', 'active'))->assertOk();
        $this->assertContains(['cancel', 'sub_webhook', 'vietfeed-delete-cancel-sub_webhook'], $this->stripe->calls);
        $this->assertSame($user->id, DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_webhook')->value('user_id'));

        // A webhook that loaded the user while it was being purged must not write the real id back.
        $this->users()->updateOne(['_id' => AccountLifecycle::userKey($user->id)], ['$set' => ['deletion.state' => AccountLifecycle::PURGING]]);
        $this->stripe->subscription = $event('evt_c', 'active')['data']['object'];
        $this->deliver($event('evt_c', 'active'))->assertOk();
        $this->assertSame(AccountLifecycle::pseudonym($user->id), DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_webhook')->value('user_id'));

        // After the purge the same row is updated by subscription id under the pseudonym instead of being dropped.
        $this->workflows()->updateOne(['_id' => $user->id], ['$set' => ['state' => AccountLifecycle::PURGED]]);
        $this->users()->deleteOne(['_id' => AccountLifecycle::userKey($user->id)]);
        DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_webhook')->update(['user_id' => AccountLifecycle::pseudonym($user->id), 'account_purged' => true]);
        $this->stripe->subscription = $event('evt_b', 'canceled')['data']['object'];
        $this->deliver($event('evt_b', 'canceled'))->assertOk();
        $row = (array) DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_webhook')->first();
        $this->assertSame(['canceled', AccountLifecycle::pseudonym($user->id)], [$row['status'], $row['user_id']]);
        $this->assertSame('projected', DB::connection('mongodb')->table('processed_stripe_events')->where('_id', 'evt_b')->value('result'));
    }

    public function test_late_stream_events_after_purge_create_no_cassandra_rows(): void
    {
        $this->cassandraSchema();
        $gone = User::factory()->create();
        $live = User::factory()->create();
        $this->cassandraUsers = [(string) $gone->id, (string) $live->id];
        $article = Article::create(['title' => 'A', 'slug' => 'late', 'original_url' => 'https://example.test/late']);
        foreach ([$gone, $live] as $subject) {
            app(InteractionToggle::class)->run(Bookmark::class, (string) $subject->id, (string) $article->id);
            $doc = ['_id' => (string) Str::uuid7(), 'user_id' => (string) $subject->id, 'article_id' => (string) $article->id, 'unlocked_at' => new UTCDateTime(now()), 'origin' => 'unlock', 'tier' => 'free'];
            app(ReadingPassHistory::class)->publish($doc);
        }
        $this->workflows()->insertOne(['_id' => (string) $gone->id, 'state' => AccountLifecycle::PURGED, 'requested_at' => new UTCDateTime, 'steps' => new \stdClass]);

        $this->artisan('interactions', ['action' => 'consume', '--limit' => 100])->assertSuccessful();
        $this->artisan('reading-pass', ['action' => 'consume', '--limit' => 100])->assertSuccessful();

        $month = now('UTC')->format('Y-m');
        $this->assertSame([], $this->rows('activity_by_user_month', 'user_id', (string) $gone->id, 'year_month', $month));
        $this->assertSame([], $this->rows('unlocks_by_user_month', 'user_id', (string) $gone->id, 'year_month', $month));
        $this->assertCount(1, $this->rows('activity_by_user_month', 'user_id', (string) $live->id, 'year_month', $month), 'control: a live account is still projected');
        $this->assertCount(1, $this->rows('unlocks_by_user_month', 'user_id', (string) $live->id, 'year_month', $month));
        $this->assertSame(0, app(InteractionStream::class)->lengths()['dead_letters']);
    }

    public function test_google_login_to_a_pending_account_goes_to_recovery_and_never_restores_or_verifies(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending-google@example.com', 'google_id' => null]);
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertGuest();

        Socialite::shouldReceive('driver->user')->once()->andReturn(
            (new SocialiteUser)->setRaw(['email_verified' => true])->map(['id' => 'google-pending', 'name' => 'Pending', 'email' => 'pending-google@example.com', 'avatar' => 'https://example.com/a.jpg'])
        );
        $this->get('/auth/google/callback')->assertRedirect(route('account.recover'));

        $fresh = $user->fresh();
        $this->assertSame(AccountLifecycle::PENDING, $fresh->deletion['state'], 'signing in does not restore');
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->google_id, 'a pending account is not linked or touched');
        $this->get(route('home'))->assertRedirect(route('account.recover'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────────────────

    private string $commentId = '';

    private string $replyId = '';

    /** @return array{0: User, 1: User, 2: Article} a user with data in every store, another user, and the article */
    private function populatedAccount(): array
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_gate97b']);
        $other = User::factory()->create();
        $id = (string) $user->id;
        $this->cassandraUsers = [$id];
        $article = Article::create(['title' => 'Bài viết', 'slug' => 'bai-viet', 'original_url' => 'https://example.test/bai-viet']);
        $aid = (string) $article->id;

        $toggle = app(InteractionToggle::class);
        $toggle->run(Bookmark::class, $id, $aid);
        $toggle->run(Boost::class, $id, $aid);
        $toggle->run(Bookmark::class, (string) $other->id, $aid);
        $this->artisan('interactions', ['action' => 'consume', '--limit' => 100])->assertSuccessful();
        $this->assertSame([$aid => 5.0], app(Trending::class)->top());

        $unlock = ['_id' => (string) Str::uuid7(), 'subject' => 'u:'.$id, 'user_id' => $id, 'article_id' => $aid, 'state' => 'granted', 'tier' => 'free', 'origin' => 'unlock', 'unlocked_at' => new UTCDateTime(now())];
        DB::connection('mongodb')->getCollection('article_unlocks')->insertOne($unlock);
        app(ReadingPassHistory::class)->apply(ReadingPassHistory::event($unlock));
        Redis::connection('nosql')->client()->set(ReadingPassQuota::windowKey('u:'.$id), 'x');

        $comment = Comment::create(['user_id' => $id, 'article_id' => $aid, 'body' => 'Bình luận của tôi']);
        $reply = Comment::create(['user_id' => (string) $other->id, 'article_id' => $aid, 'parent_id' => $comment->id, 'body' => 'Trả lời của người khác']);
        $this->commentId = (string) $comment->id;
        $this->replyId = (string) $reply->id;

        $admin = $this->superAdmin();
        Sanction::create(['user_id' => $id, 'admin_id' => $admin->id, 'type' => 'warning', 'is_active' => false, 'reason' => 'Fixture']);
        DB::connection('mongodb')->table('reports')->insert(['_id' => (string) Str::uuid7(), 'reporter_id' => $id, 'comment_id' => $this->replyId, 'created_at' => now()]);
        DB::connection('mongodb')->table('moderation_cases')->insert(['_id' => (string) Str::uuid7(), 'comment_id' => $this->commentId, 'comment_user_id' => $id]);
        $this->subscription($user);

        return [$user, $other, $article];
    }

    private function subscription(User $user): void
    {
        DB::connection('mongodb')->table('subscriptions')->insert([
            '_id' => 'sub_gate97b', 'stripe_subscription_id' => 'sub_gate97b', 'user_id' => (string) $user->id, 'type' => 'pro',
            'status' => 'active', 'price_id' => 'price_gate97b', 'stripe_customer_id' => 'cus_gate97b',
        ]);
    }

    private function makeDue(string $id): void
    {
        $past = new UTCDateTime(now()->subMinute());
        $this->users()->updateOne(['_id' => AccountLifecycle::userKey($id)], ['$set' => ['deletion.purge_after' => $past]]);
        $this->workflows()->updateOne(['_id' => $id], ['$set' => ['purge_after' => $past]]);
    }

    private function mockNeo4j(int $times): void
    {
        $this->mock(Neo4jClient::class, function (MockInterface $mock) use ($times): void {
            $mock->shouldReceive('run')->times($times)->withArgs(fn (string $query, array $params) => str_contains($query, 'MATCH (u:User {uuid: $id}) DETACH DELETE u') && isset($params['id']))
                ->andReturn(new CypherList([]));
        });
    }

    private function cassandraSchema(): void
    {
        $this->artisan('interactions', ['action' => 'schema'])->assertSuccessful();
        $this->artisan('reading-pass', ['action' => 'schema'])->assertSuccessful();
    }

    private function rows(string $table, string $column, string $id, string $bucket, string $token): array
    {
        $client = app(CassandraClient::class);
        $keyspace = $client->keyspace();

        return array_map(fn ($row) => (array) $row, $client->rows("SELECT * FROM $keyspace.$table WHERE $column = ? AND $bucket = ?", [$id, $token]));
    }

    private function expectRefusal(callable $action, string $why): void
    {
        try {
            $action();
            $this->fail("Recovery must be refused ($why).");
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode(), $why);
        }
    }

    private function deliver(array $event)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_gate97b_only')];

        return $this->call('POST', '/stripe/webhook', [], [], [], $headers, $payload);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        app(SuperAdminGuard::class)->add((string) $user->id);

        return $user;
    }

    private function users(): Collection
    {
        return DB::connection('mongodb')->getCollection('users');
    }

    private function workflows(): Collection
    {
        return DB::connection('mongodb')->getCollection(AccountLifecycle::WORKFLOWS);
    }
}
