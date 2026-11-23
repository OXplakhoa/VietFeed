<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\User;
use App\Processing\StreamConsumer;
use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\ReadingPass\UnlockLedger;
use App\Services\ReadingPassService;
use App\StoryProcessing\CassandraClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

class ReadingPassNosqlTest extends TestCase
{
    use GuardsRedisTestDatabase;
    use ModerationFixtures;

    private const LOCKED = 'Gói đọc VietFeed đã hết lượt';

    /** @var list<array{0: string, 1: string, 2: string}> Cassandra partitions written by a test */
    private array $partitions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModerationFixtures();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->partitions !== []) {
                $client = app(CassandraClient::class);
                $keyspace = $client->keyspace();
                foreach ($this->partitions as [$table, $id, $bucket]) {
                    [$column, $bucketColumn] = $table === ReadingPassHistory::BY_USER ? ['user_id', 'year_month'] : ['session_hash', 'unlock_day'];
                    $client->execute("DELETE FROM $keyspace.$table WHERE $column = ? AND $bucketColumn = ?", [$id, $bucket]);
                }
            }
        } finally {
            try {
                $this->travelBack();
                $this->flushTestDatabase(Redis::connection('nosql'));
            } finally {
                parent::tearDown();
            }
        }
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Ho_Chi_Minh'));
    }

    private function service(): ReadingPassService
    {
        return app(ReadingPassService::class);
    }

    private function articles(string $prefix, int $count): array
    {
        return array_map(fn (int $i) => $this->article("$prefix-$i"), range(1, $count));
    }

    private function seedUnlocks(User $user, int $count, string $ago): void
    {
        foreach (range(1, $count) as $i) {
            ArticleUnlock::create(['user_id' => $user->id, 'article_id' => 'seed-'.Str::uuid7(), 'unlocked_at' => now()->sub($ago)]);
        }
    }

    private function windowMembers(string $subject): array
    {
        return Redis::connection('nosql')->client()->zRange(ReadingPassQuota::windowKey($subject), 0, -1);
    }

    private function fields(string $subject, Article $article, ?User $user): array
    {
        return ['subject' => $subject, 'user_id' => $user?->id, 'session_hash' => null, 'article_id' => $article->id, 'origin' => 'unlock'];
    }

    public function test_day_windows_reset_at_vietnam_midnight_and_old_unlocks_reopen_free(): void
    {
        $this->at('2026-06-03 23:50:00');
        Session::setId(Str::random(40));
        $service = $this->service();
        $articles = $this->articles('guest-day', 7);
        foreach (array_slice($articles, 0, 5) as $article) {
            $this->assertTrue($service->accessOrLock($article, null)['canAccess']);
        }
        $denied = $service->accessOrLock($articles[5], null);
        $this->assertFalse($denied['canAccess']);
        $this->assertSame(['tier' => 'guest', 'limit' => 5, 'used' => 5, 'remaining' => 0, 'window' => 'Đã dùng 5/5 lượt hôm nay',
            'reset_label' => 'Đặt lại lúc 00:00 giờ Việt Nam', 'next_unlock_label' => 'Mở thêm lượt sau 00:00'], $denied['allowance']);
        $this->assertSame($denied['allowance'], $service->allowance(null));
        $this->assertSame(5, ArticleUnlock::where('state', 'granted')->count());
        $this->assertNull(ArticleUnlock::first()->user_id);
        $this->assertSame(UnlockLedger::sessionHash(Session::getId()), ArticleUnlock::first()->session_hash);
        $this->assertSame(0, ArticleUnlock::where('session_id', 'exists', true)->count(), 'raw session ids are never stored');

        $this->at('2026-06-04 00:00:01');
        $reopened = $service->accessOrLock($articles[0], null);
        $this->assertTrue($reopened['canAccess']);
        $this->assertSame(0, $reopened['allowance']['used'], 'yesterday unlock reopens without consuming today');
        $this->assertSame(1, $service->accessOrLock($articles[6], null)['allowance']['used']);

        $user = $this->user(['email_verified_at' => null]);
        $mine = $this->articles('unverified-day', 9);
        foreach (array_slice($mine, 0, 8) as $article) {
            $this->assertTrue($service->accessOrLock($article, $user)['canAccess']);
        }
        $locked = $service->accessOrLock($mine[8], $user);
        $this->assertFalse($locked['canAccess']);
        $this->assertSame(['unverified', 8, 8, 'Đã dùng 8/8 lượt hôm nay', 'Mở thêm lượt sau 00:00'],
            [$locked['allowance']['tier'], $locked['allowance']['limit'], $locked['allowance']['used'], $locked['allowance']['window'], $locked['allowance']['next_unlock_label']]);
        $this->assertSame(0, ArticleUnlock::where('article_id', $mine[8]->id)->count(), 'a denied reservation leaves no record');
    }

    public function test_verified_rolling_window_boundary_and_next_unlock_label(): void
    {
        $user = $this->user();
        $articles = $this->articles('rolling', 17);
        $service = $this->service();
        $this->at('2026-06-03 10:00:00');
        $this->assertTrue($service->accessOrLock($articles[0], $user)['canAccess']);
        $this->at('2026-06-03 11:00:00');
        foreach (array_slice($articles, 1, 14) as $article) {
            $this->assertTrue($service->accessOrLock($article, $user)['canAccess']);
        }
        $this->at('2026-06-03 12:00:00');
        $denied = $service->accessOrLock($articles[15], $user);
        $this->assertFalse($denied['canAccess']);
        $this->assertSame(['tier' => 'verified', 'limit' => 15, 'used' => 15, 'remaining' => 0, 'window' => 'Đã dùng 15/15 lượt trong 5 giờ qua',
            'reset_label' => 'Cửa sổ 5 giờ gần nhất', 'next_unlock_label' => 'Lượt kế tiếp sau 3 giờ 0 phút'], $denied['allowance']);
        $this->assertSame($denied['allowance'], $service->allowance($user));

        // The window start is inclusive (same as the SQL `>=`): at exactly 15:00 the 10:00 unlock still counts.
        $this->at('2026-06-03 15:00:00');
        $this->assertFalse($service->accessOrLock($articles[15], $user)['canAccess']);
        $this->assertSame('Lượt kế tiếp sau 1 phút', $service->allowance($user)['next_unlock_label']);
        $this->at('2026-06-03 15:00:01');
        $granted = $service->accessOrLock($articles[15], $user);
        $this->assertTrue($granted['canAccess']);
        $this->assertSame([15, 0, 'Lượt kế tiếp sau 59 phút'], [$granted['allowance']['used'], $granted['allowance']['remaining'], $service->allowance($user)['next_unlock_label']]);
        $this->assertFalse($service->accessOrLock($articles[16], $user)['canAccess']);
        $this->get('/profile/reading-pass')->assertRedirect();
        $this->actingAs($user)->get(route('profile.reading-pass'))->assertOk()->assertSee('Đã dùng 15/15 lượt trong 5 giờ qua');
    }

    public function test_pro_is_unlimited_and_recorded_admin_is_neither_and_admin_counts_skip_reservations(): void
    {
        $this->at('2026-06-03 12:00:00');
        $pro = $this->user();
        DB::connection('mongodb')->table('subscriptions')->insert(['user_id' => $pro->id, 'type' => 'pro', 'stripe_subscription_id' => 'sub_rp',
            'status' => 'active', 'price_id' => 'price_rp', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['services.stripe.pro_price_id' => 'price_rp']);
        $articles = $this->articles('pro', 20);
        foreach ($articles as $article) {
            $result = $this->service()->accessOrLock($article, $pro->fresh());
            $this->assertTrue($result['canAccess']);
            $this->assertSame(-1, $result['allowance']['limit']);
        }
        $this->assertSame(20, ArticleUnlock::where('user_id', $pro->id)->where('tier', 'pro')->count());
        $this->assertCount(21, $this->windowMembers(UnlockLedger::userSubject($pro->id)), 'Pro unlocks are counted in the window');

        $admin = $this->admin();
        foreach (array_slice($articles, 0, 3) as $article) {
            $this->actingAs($admin)->get(route('articles.show', $article->slug))->assertOk()->assertDontSee(self::LOCKED);
        }
        $this->assertSame(0, ArticleUnlock::where('user_id', $admin->id)->count());
        $this->assertSame(0, Redis::connection('nosql')->client()->exists(ReadingPassQuota::windowKey(UnlockLedger::userSubject($admin->id))));

        // A reservation still in flight is not an unlock for admin metrics.
        app(UnlockLedger::class)->reserve($this->fields(UnlockLedger::userSubject($pro->id), $this->article('pending-only'), $pro), now());
        ArticleUnlock::create(['session_id' => 'old-guest', 'article_id' => $articles[0]->id, 'unlocked_at' => now()->subHours(6)]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['unlocks_today'] === 21 && $stats['unlocks_5h'] === 20);
        $users = $this->actingAs($admin)->get('/admin/users')->assertOk()->viewData('users')->keyBy('id');
        $this->assertSame([20, 0], [$users[$pro->id]->article_unlocks_count, $users[$admin->id]->article_unlocks_count]);
        $this->assertSame(-1, $users[$pro->id]->reading_pass_allowance['limit']);
        $this->assertSame(2, $this->actingAs($admin)->get('/admin/articles/'.$articles[0]->id)->assertOk()->viewData('article')->unlocks_count);

        // Pro lapses: recorded Pro unlocks count against the verified rolling window (legacy behaviour).
        DB::connection('mongodb')->table('subscriptions')->delete();
        $allowance = $this->service()->allowance(User::find($pro->id));
        $this->assertSame(['verified', 20, 0], [$allowance['tier'], $allowance['used'], $allowance['remaining']]);
    }

    public function test_eviction_and_flush_rebuild_the_same_decision_and_label(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $this->seedUnlocks($user, 14, '2 hours');
        $articles = $this->articles('evict', 3);
        $service = $this->service();
        $this->assertTrue($service->accessOrLock($articles[0], $user)['canAccess']);
        $subject = UnlockLedger::userSubject($user->id);
        $expected = $service->allowance($user);
        $this->assertSame([15, 'Lượt kế tiếp sau 3 giờ 0 phút'], [$expected['used'], $expected['next_unlock_label']]);
        $this->assertCount(16, $this->windowMembers($subject));

        foreach (['evict' => fn () => Redis::connection('nosql')->del(ReadingPassQuota::windowKey($subject)),
            'flush' => fn () => $this->flushTestDatabase(Redis::connection('nosql')),
            'generation' => fn () => app(ReadingPassQuota::class)->invalidateAll()] as $case => $lose) {
            $lose();
            $this->assertSame($expected, $service->allowance($user), "$case: allowance answered from MongoDB");
            $this->assertTrue($service->accessOrLock($articles[0], $user)['canAccess'], "$case: already unlocked stays free");
            $denied = $service->accessOrLock($articles[1], $user);
            $this->assertFalse($denied['canAccess'], "$case: no extra unlock after the loss");
            $this->assertSame($expected, $denied['allowance'], $case);
            $this->assertCount(16, $this->windowMembers($subject), "$case: window rebuilt with sentinel");
        }
        $this->assertSame(15, ArticleUnlock::where('user_id', $user->id)->count());
    }

    public function test_rebuild_counts_an_in_flight_grant_and_resolves_stale_reservations_from_mongo(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $this->seedUnlocks($user, 14, '1 hour');
        $subject = UnlockLedger::userSubject($user->id);
        $ledger = app(UnlockLedger::class);
        $quota = app(ReadingPassQuota::class);
        $service = $this->service();
        [$x, $z, $w] = $this->articles('flight', 3);

        // Request A: reservation, then Redis grants, then the window is evicted before A records the grant.
        $a = $ledger->reserve($this->fields($subject, $x, $user), now());
        $this->assertSame(-1, $quota->decide($subject, $x->id, UnlockLedger::ms(now()), UnlockLedger::ms(now()->subHours(5)), 15)['status']);
        $this->assertTrue($this->invokeRebuild($user));
        $this->assertSame(ReadingPassQuota::GRANTED, $quota->decide($subject, $x->id, UnlockLedger::ms(now()), UnlockLedger::ms(now()->subHours(5)), 15)['status']);
        $quota->forget($subject);

        // Request B rebuilds while A is in flight: A's reservation is loaded as p: and counted.
        $b = $service->accessOrLock($z, $user);
        $this->assertFalse($b['canAccess']);
        $this->assertSame(15, $b['allowance']['used']);
        $this->assertContains('p:'.$x->id, $this->windowMembers($subject));

        $ledger->grant($a, $this->fields($subject, $x, $user), now(), 'verified', false);
        $this->travel(61)->seconds();
        $c = $service->accessOrLock($w, $user);
        $this->assertFalse($c['canAccess'], 'the resolved grant still counts');
        $this->assertContains('g:'.$x->id, $this->windowMembers($subject));
        $this->assertNotContains('p:'.$x->id, $this->windowMembers($subject));

        // An abandoned reservation (crashed request) is counted while fresh, then released after 60 s.
        $other = $this->user();
        $this->seedUnlocks($other, 13, '1 hour');
        $otherSubject = UnlockLedger::userSubject($other->id);
        [$q, $r1, $r2] = $this->articles('abandoned', 3);
        $crashed = $ledger->reserve($this->fields($otherSubject, $q, $other), now());
        $this->assertTrue($service->accessOrLock($r1, $other)['canAccess']);
        $this->assertFalse($service->accessOrLock($r2, $other)['canAccess'], 'fresh reservation counted conservatively');
        $this->travel(61)->seconds();
        $this->assertTrue($service->accessOrLock($r2, $other)['canAccess']);
        $this->assertNull(ArticleUnlock::find($crashed));
        $this->assertSame(15, ArticleUnlock::where('user_id', $other->id)->where('state', 'granted')->count());
    }

    private function invokeRebuild(User $user): bool
    {
        $method = new \ReflectionMethod(ReadingPassService::class, 'rebuild');

        return $method->invoke($this->service(), UnlockLedger::userSubject($user->id), CarbonImmutable::now('Asia/Ho_Chi_Minh'));
    }

    public function test_concurrent_workers_at_the_limit_never_exceed_it(): void
    {
        $cold = $this->user();
        $this->seedUnlocks($cold, 12, '10 minutes');
        $warm = $this->user();
        $this->seedUnlocks($warm, 13, '10 minutes');
        $this->service()->accessOrLock($this->article('warm-up'), $warm); // 14 used, window built
        $this->assertCount(15, $this->windowMembers(UnlockLedger::userSubject($warm->id)));

        // Cold window: every worker also races the rebuild lock.
        $results = $this->race($cold, $this->articles('race-cold', 6));
        $this->assertSame(3, count(array_filter($results)));
        $this->assertSame(15, ArticleUnlock::where('user_id', $cold->id)->where('state', 'granted')->count());
        $this->assertSame(0, ArticleUnlock::where('user_id', $cold->id)->where('state', 'pending')->count());

        // Built window: the Lua decision alone serializes the requests.
        $results = $this->race($warm, $this->articles('race-warm', 5));
        $this->assertSame(1, count(array_filter($results)));
        $this->assertSame(15, ArticleUnlock::where('user_id', $warm->id)->where('state', 'granted')->count());
        $this->assertSame(15, $this->service()->allowance($warm)['used']);
    }

    /** @return list<bool> */
    private function race(User $user, array $articles): array
    {
        // Independent PHP processes, each with its own Mongo and Redis connections on the isolated stores.
        $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        foreach (['APP_ENV'=>'testing','MONGO_DB'=>'vietfeed_test','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','CASSANDRA_KEYSPACE'=>'vietfeed_test','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
        }
        if (file_exists(getcwd().'/bootstrap/cache/config.php')) { exit(10); }
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config('database.connections.mongodb.database') !== 'vietfeed_test' || config('database.default') !== 'mongodb' || array_keys(config('database.connections')) !== ['mongodb']) { exit(11); }
        foreach (['nosql','default','cache'] as $name) { config(['database.redis.'.$name.'.url'=>null,'database.redis.'.$name.'.database'=>15]); }
        foreach (['nosql','default','cache'] as $name) { if (\Illuminate\Support\Facades\Redis::connection($name)->client()->getDbNum() !== 15) { exit(12); } }
        $service = app(\App\Services\ReadingPassService::class);
        echo "ready\n"; fflush(STDOUT);
        $input = json_decode(fgets(STDIN), true);
        $user = \App\Models\User::findOrFail($input['user']);
        $article = \App\Models\Article::findOrFail($input['article']);
        while (microtime(true) < $input['at']) { usleep(200); }
        echo json_encode(['granted' => $service->accessOrLock($article, $user)['canAccess']]);
        PHP;
        $workers = [];
        try {
            foreach ($articles as $article) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $article];
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            $at = microtime(true) + 0.3;
            foreach ($workers as [, $pipes, $article]) {
                fwrite($pipes[0], json_encode(['user' => $user->id, 'article' => $article->id, 'at' => $at])."\n");
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), 'worker must exit cleanly: '.$errors);
                $this->assertSame('', $errors);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['granted'];
            }

            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    public function test_redis_down_fails_open_on_the_ledger_then_rebuilds_after_recovery(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $this->seedUnlocks($user, 13, '1 hour');
        [$first, $during, $over, $after] = $this->articles('outage', 4);
        $service = $this->service();
        $this->assertTrue($service->accessOrLock($first, $user)['canAccess']); // 14 used, window built
        $subject = UnlockLedger::userSubject($user->id);

        $manager = Redis::getFacadeRoot();
        Log::spy();
        Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RedisException('Synthetic outage with private diagnostic'));
        try {
            $granted = $service->accessOrLock($during, $user);
            $this->assertTrue($granted['canAccess']);
            $this->assertSame(15, $granted['allowance']['used']);
            $denied = $service->accessOrLock($over, $user);
            $this->assertFalse($denied['canAccess'], 'the ledger count still enforces the limit');
            $this->assertSame('Lượt kế tiếp sau 4 giờ 0 phút', $denied['allowance']['next_unlock_label']);
            $this->assertSame(15, $service->allowance($user)['used']);
            $this->assertTrue($service->accessOrLock($first, $user)['canAccess']);
        } finally {
            Redis::swap($manager);
        }
        Log::shouldHaveReceived('warning')->with('Reading Pass quota unavailable', \Mockery::on(fn ($context) => $context['exception_type'] === \RuntimeException::class || $context['exception_type'] === \RedisException::class));
        Log::shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(fn ($context) => str_contains(json_encode($context), 'private'))]);
        $this->assertTrue(ArticleUnlock::where('article_id', $during->id)->sole()->fallback);

        // Redis is back with its pre-outage window (14): the fallback flag forces a rebuild before deciding.
        $this->assertNotContains('g:'.$during->id, $this->windowMembers($subject));
        $this->assertFalse($service->accessOrLock($after, $user)['canAccess']);
        $this->assertContains('g:'.$during->id, $this->windowMembers($subject));
        $this->assertSame(0, ArticleUnlock::where('fallback', true)->count());
        $this->assertSame(15, ArticleUnlock::where('user_id', $user->id)->count());
    }

    public function test_guest_unlocks_transfer_on_login_register_and_google_without_duplicates(): void
    {
        $this->at('2026-06-03 12:00:00');
        [$shared, $guestOnly, $mineOnly] = $this->articles('transfer', 3);
        $guestOpens = function (array $articles): string {
            $sid = Str::random(40);
            foreach ($articles as $article) {
                $this->withCookie(config('session.cookie'), $sid)->get(route('articles.show', $article->slug))->assertOk()->assertDontSee(self::LOCKED);
            }
            $this->assertSame(count($articles), ArticleUnlock::where('session_hash', UnlockLedger::sessionHash($sid))->count());

            return $sid;
        };

        $login = $this->user(['email' => 'login@example.test', 'password' => bcrypt('password')]);
        $this->service()->accessOrLock($shared, $login);
        $this->service()->accessOrLock($mineOnly, $login);
        $before = $this->service()->allowance($login);
        $sid = $guestOpens([$shared, $guestOnly]);
        $this->withCookie(config('session.cookie'), $sid)->post('/login', ['email' => 'login@example.test', 'password' => 'password'])->assertRedirect();
        $this->assertEqualsCanonicalizing([$shared->id, $guestOnly->id, $mineOnly->id], ArticleUnlock::where('user_id', $login->id)->pluck('article_id')->all());
        $this->assertSame(0, ArticleUnlock::whereNull('user_id')->count());
        $this->assertSame($before['used'] + 1, $this->service()->allowance($login)['used'], 'window rebuilt with the moved unlock');
        $moved = ArticleUnlock::where('user_id', $login->id)->where('article_id', $guestOnly->id)->sole();
        $this->assertSame(['transfer', null], [$moved->origin, $moved->session_hash]);
        auth()->guard('web')->logout();

        $sid = $guestOpens([$guestOnly]);
        $this->withCookie(config('session.cookie'), $sid)->post('/register', ['name' => 'Mới', 'email' => 'new@example.test',
            'password' => 'password-123', 'password_confirmation' => 'password-123'])->assertRedirect();
        $registered = User::where('email', 'new@example.test')->sole();
        $this->assertSame([$guestOnly->id], ArticleUnlock::where('user_id', $registered->id)->pluck('article_id')->all());
        auth()->guard('web')->logout();

        $sid = $guestOpens([$shared, $mineOnly]);
        $google = (new SocialiteUser)->setRaw(['email_verified' => true]);
        $google->map(['id' => 'g-rp', 'name' => 'Google', 'email' => 'login@example.test', 'avatar' => null]);
        Socialite::shouldReceive('driver->user')->once()->andReturn($google);
        $this->withCookie(config('session.cookie'), $sid)->get('/auth/google/callback')->assertRedirect();
        $this->assertSame(3, ArticleUnlock::where('user_id', $login->id)->count(), 'duplicates dropped, no double count');
        $this->assertSame(0, ArticleUnlock::whereNull('user_id')->count());
    }

    public function test_transfer_with_redis_down_marks_moved_unlocks_for_rebuild(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $this->seedUnlocks($user, 14, '1 hour');
        [$first, $next] = $this->articles('transfer-down', 2);
        $this->assertTrue($this->invokeRebuild($user)); // window built at 14
        $this->assertSame(14, $this->service()->allowance($user)['used']);
        ArticleUnlock::create(['session_id' => 'guest-down', 'article_id' => $first->id, 'unlocked_at' => now()]);

        $manager = Redis::getFacadeRoot();
        Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RedisException('down'));
        try {
            $this->service()->transferGuestUnlocks($user, 'guest-down');
        } finally {
            Redis::swap($manager);
        }
        $this->assertTrue(ArticleUnlock::where('user_id', $user->id)->where('article_id', $first->id)->sole()->fallback);
        $this->assertFalse($this->service()->accessOrLock($next, $user)['canAccess'], 'stale 14-window is rebuilt to 15 before deciding');
    }

    public function test_cleanup_deletes_old_guest_unlocks_and_abandoned_reservations_only(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $old = ArticleUnlock::create(['session_id' => 'old', 'article_id' => 'a1', 'unlocked_at' => now()->subDays(31)]);
        $recent = ArticleUnlock::create(['session_id' => 'recent', 'article_id' => 'a2', 'unlocked_at' => now()->subDays(29)]);
        $mine = ArticleUnlock::create(['user_id' => $user->id, 'article_id' => 'a3', 'unlocked_at' => now()->subDays(90)]);
        $ledger = app(UnlockLedger::class);
        $abandoned = $ledger->reserve($this->fields('u:crashed', $this->article('crashed'), null), now()->subHours(2));
        $inFlight = $ledger->reserve($this->fields('u:in-flight', $this->article('in-flight'), null), now());

        $this->artisan('unlocks:cleanup')->expectsOutput('Deleted 1 old guest unlock(s).')->expectsOutput('Deleted 1 abandoned reservation(s).')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$recent->id, $mine->id, $inFlight], ArticleUnlock::all()->map(fn ($unlock) => (string) $unlock->id)->all());
        $this->assertNull(ArticleUnlock::find($old->id));
        $this->assertNull(ArticleUnlock::find($abandoned));

        // A slow but live owner whose reservation was swept still records its grant under the same id.
        $slow = $this->article('slow-owner');
        $fields = $this->fields(UnlockLedger::userSubject($user->id), $slow, $user);
        $id = $ledger->reserve($fields, now());
        ArticleUnlock::find($id)->delete();
        $doc = $ledger->grant($id, $fields, now(), 'verified', false);
        $this->assertSame([$id, 'granted', $slow->id, $user->id], [$doc['_id'], $doc['state'], $doc['article_id'], $doc['user_id']]);
        $this->assertSame(1, ArticleUnlock::where('article_id', $slow->id)->count());
    }

    public function test_history_projects_to_cassandra_idempotently_with_dlq_replay_and_reemit(): void
    {
        $this->at('2026-06-03 12:00:00');
        $history = app(ReadingPassHistory::class);
        $history->bootstrap();
        $history->bootstrap();
        $user = $this->user();
        [$a, $b] = $this->articles('history', 2);
        $this->service()->accessOrLock($a, $user);
        Session::setId($sid = Str::random(40));
        $this->service()->accessOrLock($b, null);
        $month = now('UTC')->format('Y-m');
        $day = now('UTC')->format('Y-m-d');
        $this->partitions = [[ReadingPassHistory::BY_USER, $user->id, $month], [ReadingPassHistory::BY_SESSION, UnlockLedger::sessionHash($sid), $day]];

        $this->assertSame(2, $history->consume('test', 10));
        $rows = $history->forUserMonth($user->id, $month);
        $unlock = ArticleUnlock::where('user_id', $user->id)->sole();
        $this->assertSame([['unlocked_at' => '2026-06-03T05:00:00.000Z', 'unlock_id' => $unlock->id, 'article_id' => $a->id, 'tier' => 'verified', 'origin' => 'unlock', 'backfill' => false]], $rows);
        $this->assertCount(1, $history->forSessionDay(UnlockLedger::sessionHash($sid), $day));
        $this->artisan('reading-pass', ['action' => 'history', '--user' => $user->id, '--month' => $month])->expectsOutputToContain($a->id)->assertSuccessful();

        $this->artisan('reading-pass', ['action' => 'reemit'])->expectsOutput('Published 2 unlocks, 0 failed')->assertSuccessful();
        $this->assertSame(2, $history->consume('reemit', 10));
        $this->assertSame($rows, $history->forUserMonth($user->id, $month), 'replays overwrite the same row');

        $stream = $history->stream();
        $stream->publish(['event_id' => 'not-a-uuid']);
        $redis = Redis::connection('nosql')->client();
        $this->assertSame(1, $history->consume('poison'));
        for ($attempt = 2; $attempt <= StreamConsumer::MAX_ATTEMPTS; $attempt++) {
            $pending = $stream->pending()[0];
            $redis->xClaim(ReadingPassHistory::STREAM, ReadingPassHistory::GROUP, $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            $this->assertSame(1, $history->consume('retry'));
        }
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $this->artisan('reading-pass', ['action' => 'inspect'])->expectsOutputToContain('"dead_letters": 1')->assertSuccessful();
        $this->artisan('reading-pass', ['action' => 'replay', '--id' => array_key_first($stream->deadLetters())])->assertSuccessful();
        $this->assertSame(['stream' => 1, 'pending' => 0, 'dead_letters' => 0], $stream->lengths());
        $this->assertSame($rows, $history->forUserMonth($user->id, $month));
    }

    public function test_same_article_loser_settles_on_a_slow_winner_and_never_locks_with_room_left(): void
    {
        $user = $this->user();
        $this->seedUnlocks($user, 13, '10 minutes');
        $subject = UnlockLedger::userSubject($user->id);
        $ledger = app(UnlockLedger::class);
        [$granted, $released, $stuck] = $this->articles('settle', 3);

        // The winner holds the reservation for 0.8 s (cold rebuild + slow Redis), far beyond the old ~150 ms wait.
        foreach (['grant' => $granted, 'release' => $released] as $action => $article) {
            $fields = $this->fields($subject, $article, $user);
            $id = $ledger->reserve($fields, now());
            $worker = $this->worker(<<<'PHP'
            usleep(800000);
            $ledger = app(\App\ReadingPass\UnlockLedger::class);
            $input['action'] === 'grant'
                ? $ledger->grant($input['id'], $input['fields'], \Carbon\CarbonImmutable::now(), 'verified', false)
                : $ledger->release($input['id']);
            echo 'done';
            PHP, ['id' => $id, 'fields' => $fields, 'action' => $action]);
            $started = hrtime(true);
            $result = $this->service()->accessOrLock($article, $user);
            $this->assertSame('done', $this->finish($worker));
            $this->assertGreaterThan(700, (hrtime(true) - $started) / 1e6, "$action: the loser waited for the winner");
            $this->assertTrue($result['canAccess'], $action);
            $this->assertSame(1, ArticleUnlock::where('article_id', $article->id)->where('state', 'granted')->count(), $action);
        }
        $this->assertSame(15, ArticleUnlock::where('user_id', $user->id)->count(), 'released winner: the loser took the last slot');
        $this->assertSame(1, $ledger->collection()->countDocuments(['article_id' => $granted->id]), 'the loser added no second record');

        // A winner that never settles within the bound: room left → shown without a record; no room → locked.
        config(['nosql.reading_pass_settle_ms' => 300]);
        $roomy = $this->user();
        $this->seedUnlocks($roomy, 13, '10 minutes');
        $ledger->reserve($this->fields(UnlockLedger::userSubject($roomy->id), $stuck, $roomy), now());
        $shown = $this->service()->accessOrLock($stuck, $roomy);
        $this->assertTrue($shown['canAccess']);
        $this->assertSame(14, $shown['allowance']['used'], 'counted as the winner\'s unlock, not a second one');
        $this->assertSame(1, ArticleUnlock::where('user_id', $roomy->id)->where('article_id', $stuck->id)->count());
        $full = $this->user();
        $this->seedUnlocks($full, 15, '10 minutes');
        $ledger->reserve($this->fields(UnlockLedger::userSubject($full->id), $stuck, $full), now());
        $this->assertFalse($this->service()->accessOrLock($stuck, $full)['canAccess']);
    }

    public function test_failed_forced_rebuild_counts_the_ledger_instead_of_a_stale_window(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $this->seedUnlocks($user, 13, '1 hour');
        [$first, $outage, $next] = $this->articles('forced', 3);
        $this->assertTrue($this->service()->accessOrLock($first, $user)['canAccess']); // window 14
        $subject = UnlockLedger::userSubject($user->id);
        $doc = ArticleUnlock::create(['user_id' => $user->id, 'article_id' => $outage->id, 'unlocked_at' => now()]);
        app(UnlockLedger::class)->collection()->updateOne(['_id' => $doc->id], ['$set' => ['fallback' => true]]); // granted during an outage
        $redis = Redis::connection('nosql')->client();
        $redis->set(ReadingPassQuota::lockKey($subject), 'another-holder', ['EX' => 10]);

        $denied = $this->service()->accessOrLock($next, $user);
        $this->assertFalse($denied['canAccess'], 'the stale 14-window must not decide');
        $this->assertSame(15, $denied['allowance']['used']);
        $this->assertTrue($doc->fresh()->fallback, 'flag kept until a rebuild covers it');

        $redis->del(ReadingPassQuota::lockKey($subject));
        $this->assertFalse($this->service()->accessOrLock($next, $user)['canAccess']);
        $this->assertNull($doc->fresh()->fallback);
        $this->assertContains('g:'.$outage->id, $this->windowMembers($subject));
    }

    public function test_missing_generation_key_means_not_built_and_out_of_band_ledger_rows_are_counted(): void
    {
        $this->at('2026-06-03 12:00:00');
        $redis = Redis::connection('nosql')->client();
        $this->assertSame(0, $redis->exists(ReadingPassQuota::GEN));
        $user = $this->user();
        $subject = UnlockLedger::userSubject($user->id);
        $this->service()->accessOrLock($this->article('gen-before'), $user);
        $this->assertIsString($redis->get(ReadingPassQuota::GEN), 'rebuild creates a generation with SET NX');
        // A granted ledger row written outside the quota window (formerly by the removed SQL import).
        app(UnlockLedger::class)->collection()->insertOne(['_id' => (string) Str::uuid7(), 'subject' => $subject, 'user_id' => (string) $user->id,
            'session_hash' => null, 'article_id' => 'gen-legacy', 'state' => UnlockLedger::GRANTED, 'unlocked_at' => UnlockLedger::date(now('UTC')->subHour()),
            'tier' => null, 'origin' => 'seed', 'created_at' => UnlockLedger::date(now('UTC')), 'updated_at' => UnlockLedger::date(now('UTC'))]);

        $redis->del(ReadingPassQuota::GEN); // evicted
        $this->assertSame(2, $this->service()->allowance($user)['used'], 'no generation: answered from MongoDB');
        $this->assertSame(3, $this->service()->accessOrLock($this->article('gen-after'), $user)['allowance']['used']);
        $this->assertContains('g:gen-legacy', $this->windowMembers($subject));
        $this->assertContains('~ready:'.$redis->get(ReadingPassQuota::GEN), $this->windowMembers($subject));

        // A window carrying the empty-generation sentinel is never trusted.
        $other = $this->user();
        $key = ReadingPassQuota::windowKey(UnlockLedger::userSubject($other->id));
        $redis->del(ReadingPassQuota::GEN);
        $redis->zAdd($key, -1, '~ready:', UnlockLedger::ms(now()), 'g:ghost-1', UnlockLedger::ms(now()), 'g:ghost-2');
        $this->assertSame(0, $this->service()->allowance($other)['used']);
    }

    public function test_contended_transfer_still_drops_the_user_window(): void
    {
        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $subject = UnlockLedger::userSubject($user->id);
        [$mine, $guest] = $this->articles('contended', 2);
        $this->service()->accessOrLock($mine, $user);
        $this->assertSame(1, $this->service()->allowance($user)['used']);
        ArticleUnlock::create(['session_id' => 'contended-session', 'article_id' => $guest->id, 'unlocked_at' => now()]);
        $redis = Redis::connection('nosql')->client();
        $redis->set(ReadingPassQuota::lockKey($subject), 'another-holder', ['EX' => 10]);

        $this->service()->transferGuestUnlocks($user, 'contended-session');
        $this->assertSame(0, $redis->exists(ReadingPassQuota::windowKey($subject)));
        $this->assertSame(2, $this->service()->allowance($user)['used'], 'allowance right after the transfer includes the moved unlock');
        $this->assertTrue(ArticleUnlock::where('user_id', $user->id)->where('article_id', $guest->id)->sole()->fallback);
        $this->assertSame('another-holder', $redis->get(ReadingPassQuota::lockKey($subject)), 'a foreign lock is never released');
    }

    /** One isolated PHP worker running $body with `$input` decoded from stdin. */
    private function worker(string $body, array $input): array
    {
        $boot = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        foreach (['APP_ENV'=>'testing','MONGO_DB'=>'vietfeed_test','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','CASSANDRA_KEYSPACE'=>'vietfeed_test','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
        }
        if (file_exists(getcwd().'/bootstrap/cache/config.php')) { exit(10); }
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config('database.connections.mongodb.database') !== 'vietfeed_test' || config('database.default') !== 'mongodb' || array_keys(config('database.connections')) !== ['mongodb']) { exit(11); }
        foreach (['nosql','default','cache'] as $name) { config(['database.redis.'.$name.'.url'=>null,'database.redis.'.$name.'.database'=>15]); }
        foreach (['nosql','default','cache'] as $name) { if (\Illuminate\Support\Facades\Redis::connection($name)->client()->getDbNum() !== 15) { exit(12); } }
        echo "ready\n"; fflush(STDOUT);
        $input = json_decode(fgets(STDIN), true);
        PHP;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $boot."\n".$body], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        $this->assertSame("ready\n", fgets($pipes[1]));
        fwrite($pipes[0], json_encode($input)."\n");
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function finish(array $worker): string
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'worker must exit cleanly: '.$errors);
        $this->assertSame('', $errors);

        return $output;
    }

    public function test_no_code_reads_sql_article_unlocks(): void
    {
        $hits = [];
        foreach (['app', 'routes', 'resources', 'database/seeders'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->isFile() && preg_match('/(DB::table|->table)\(\s*[\'"]article_unlocks[\'"]|exists:article_unlocks\b|(FROM|JOIN|INTO|UPDATE)\s+[`"]?article_unlocks\b/i', file_get_contents($file->getPathname()))) {
                    $hits[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }
        $this->assertSame([], $hits, 'Gate 9.5 slice 5: the SQL import was removed');
        $this->assertSame('mongodb', (new ArticleUnlock)->getConnectionName());

        $this->at('2026-06-03 12:00:00');
        $user = $this->user();
        $article = $this->article('runtime-proof');
        $admin = $this->admin();
        $sql = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql) {
            if ($q->connectionName !== 'mongodb') {
                $sql[] = $q->sql;
            }
        });
        $this->get(route('articles.show', $article->slug))->assertOk();
        $this->actingAs($user)->get(route('articles.show', $article->slug))->assertOk()->assertSee('14/15 lượt còn lại');
        $this->actingAs($user)->get(route('profile.reading-pass'))->assertOk();
        foreach (['/admin/dashboard', '/admin/users', '/admin/articles/'.$article->id] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
        $this->artisan('unlocks:cleanup')->assertSuccessful();
        // Gate 9.5 slice 5: no SQL connection is configured, so nothing reaches SQL at all.
        $this->assertSame([], $sql);
        $this->assertSame(['mongodb'], array_keys(DB::getConnections()));
    }

    public function test_indexes_are_rerunnable_and_references_are_bson_strings(): void
    {
        $migration = require database_path('migrations/2026_11_18_220001_create_mongodb_article_unlock_indexes.php');
        $migration->up();
        $migration->up();
        $indexes = collect(iterator_to_array(DB::connection('mongodb')->getCollection('article_unlocks')->listIndexes()))->keyBy(fn ($index) => $index->getName());
        $this->assertTrue($indexes['subject_1_article_id_1']->isUnique());
        $this->assertSame(['fallback' => true], (array) $indexes['subject_1_fallback_1']['partialFilterExpression']);
        $this->service()->accessOrLock($this->article('bson'), $user = $this->user());
        $raw = app(UnlockLedger::class)->collection()->findOne(['user_id' => $user->id]);
        $this->assertIsString($raw['_id']);
        $this->assertIsString($raw['article_id']);
        $this->assertInstanceOf(UTCDateTime::class, $raw['unlocked_at']);
        $this->expectException(BulkWriteException::class);
        app(UnlockLedger::class)->collection()->insertOne(['subject' => $raw['subject'], 'article_id' => $raw['article_id']]);
    }
}
