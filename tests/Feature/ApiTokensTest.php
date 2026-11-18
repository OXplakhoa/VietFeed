<?php

namespace Tests\Feature;

use App\ApiTokens\ApiTokenService;
use App\ApiTokens\TokenException;
use App\Http\Middleware\ApiBearer;
use App\Interaction\InteractionStream;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\Source;
use App\Models\Story;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class ApiTokensTest extends TestCase
{
    use GuardsRedisTestDatabase, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateRedis(['nosql', 'default', 'cache']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabase(Redis::connection('nosql'));
        parent::tearDown();
    }

    public function test_module_issues_independent_opaque_pairs_and_authenticates_current_mongo_user(): void
    {
        $user = User::factory()->create();
        $service = app(ApiTokenService::class);
        $pair = $service->issue($user);
        $this->assertGreaterThanOrEqual(43, strlen($pair['access_token']));
        $this->assertTrue($pair['access_token'] !== $pair['refresh_token'], 'Token kinds must differ');
        $this->assertSame((string) $user->id, (string) $service->authenticate($pair['access_token'])['user']->id);
        $user->update(['name' => 'Current name']);
        $this->assertSame('Current name', $service->authenticate($pair['access_token'])['user']->name);
    }

    public function test_http_login_is_stateless_and_returns_no_store_tokens(): void
    {
        $user = User::factory()->create();
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(app('session')->driver()->isStarted());
        $this->withToken($response->json('access_token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonMissingPath('user.password');
        $this->assertFalse(app('session')->driver()->isStarted());
    }

    private function login(?User $user = null): array
    {
        $user ??= User::factory()->create();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json();
    }

    private function me(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/auth/me');
    }

    private function refreshToken(string $token): TestResponse
    {
        return $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $token]);
    }

    public function test_rotation_preserves_old_access_but_reuse_revokes_every_generation(): void
    {
        $first = $this->login();
        $second = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $this->assertTrue($first['refresh_token'] !== $second['refresh_token'], 'Refresh token must rotate');
        $this->assertTrue($first['access_token'] !== $second['access_token'], 'Access token must rotate');
        $this->me($first['access_token'])->assertOk();
        $this->me($second['access_token'])->assertOk();
        $third = $this->refreshToken($second['refresh_token'])->assertOk()->json();
        $this->refreshToken($first['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'refresh_token_reused');
        foreach ([$first, $second, $third] as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
    }

    public function test_logout_revokes_only_presented_family(): void
    {
        $user = User::factory()->create();
        $first = $this->login($user);
        $second = $this->login($user);
        $rotated = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $this->withToken($rotated['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
        $this->me($first['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        $this->refreshToken($first['refresh_token'])->assertUnauthorized();
        $this->me($rotated['access_token'])->assertUnauthorized();
        $this->me($second['access_token'])->assertOk();
    }

    public function test_web_password_update_revokes_all_families(): void
    {
        $user = User::factory()->create();
        $first = $this->login($user);
        $second = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $third = $this->login($user);
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertSessionHasNoErrors()->assertRedirect();
        foreach ([$first, $second, $third] as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized();
        }
    }

    public function test_password_reset_revokes_all_families_through_existing_web_action(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $pairs = [$this->login($user), $this->login($user)];
        $pairs[] = $this->refreshToken($pairs[0]['refresh_token'])->assertOk()->json();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'reset-password', 'password_confirmation' => 'reset-password'])
                ->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });
        foreach ($pairs as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized();
        }
    }

    public function test_self_deletion_revokes_every_family(): void
    {
        $user = User::factory()->create();
        $pairs = [$this->login($user), $this->login($user)];
        $pairs[] = $this->refreshToken($pairs[0]['refresh_token'])->assertOk()->json();
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertNull(User::find($user->id));
        foreach ($pairs as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized();
        }
    }

    public function test_admin_deletion_revokes_every_family(): void
    {
        $user = User::factory()->create();
        $pairs = [$this->login($user), $this->login($user)];
        $pairs[] = $this->refreshToken($pairs[0]['refresh_token'])->assertOk()->json();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->delete('/admin/users/'.$user->id)->assertRedirect();
        $this->assertNull(User::find($user->id));
        foreach ($pairs as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized();
        }
    }

    public function test_admin_permanent_ban_revokes_all_families_and_denies_login(): void
    {
        $user = User::factory()->create();
        $pairs = [$this->login($user), $this->login($user)];
        $pairs[] = $this->refreshToken($pairs[0]['refresh_token'])->assertOk()->json();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/sanctions', ['user_id' => $user->id, 'type' => 'permanent_ban', 'reason' => 'Test fixture'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.sanctions.index'));
        foreach ($pairs as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()->assertExactJson(['error' => ['code' => 'invalid_credentials']]);
    }

    public function test_mongo_deletion_or_ban_is_authoritative_even_when_hooks_are_bypassed(): void
    {
        $user = User::factory()->create();
        $pair = $this->login($user);
        User::where('_id', $user->id)->delete();
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        $other = User::factory()->create();
        $pair = $this->login($other);
        Sanction::withoutEvents(fn () => Sanction::create(['user_id' => $other->id, 'admin_id' => $other->id, 'type' => 'permanent_ban', 'reason' => 'Fixture', 'is_active' => true]));
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
    }

    public static function expiryOrders(): array
    {
        return [[300, 120], [120, 300]];
    }

    #[DataProvider('expiryOrders')]
    public function test_each_token_obeys_its_own_expiry(int $accessTtl, int $refreshTtl): void
    {
        config(['nosql.api_tokens.access_ttl_seconds' => $accessTtl, 'nosql.api_tokens.refresh_ttl_seconds' => $refreshTtl]);
        $pair = $this->login();
        $this->assertSame($accessTtl, $pair['expires_in']);
        $this->assertSame($refreshTtl, $pair['refresh_expires_in']);
        $this->expireToken($refreshTtl < $accessTtl ? 'refresh' : 'access', $pair[($refreshTtl < $accessTtl ? 'refresh' : 'access').'_token']);
        if ($refreshTtl < $accessTtl) {
            $this->me($pair['access_token'])->assertOk();
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'refresh_token_expired');
        } else {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'access_token_expired');
            $replacement = $this->refreshToken($pair['refresh_token'])->assertOk()->json();
            $this->me($replacement['access_token'])->assertOk();
        }
        $this->expireToken('access', $pair['access_token']);
        $this->expireToken('refresh', $pair['refresh_token']);
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'access_token_expired');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'refresh_token_expired');
    }

    public function test_invalid_and_cross_kind_tokens_and_generic_json_errors(): void
    {
        $pair = $this->login();
        foreach (['', bin2hex(random_bytes(32)), $pair['refresh_token'], $pair['access_token'].'x'] as $bad) {
            $this->me($bad)->assertUnauthorized()->assertExactJson(['error' => ['code' => 'invalid_access_token']]);
        }
        foreach ([bin2hex(random_bytes(32)), $pair['access_token'], $pair['refresh_token'].'x'] as $bad) {
            $this->refreshToken($bad)->assertUnauthorized()->assertExactJson(['error' => ['code' => 'invalid_refresh_token']]);
        }
        $this->post('/api/v1/auth/login', [])->assertStatus(422)->assertExactJson(['error' => ['code' => 'validation_error']]);
        $this->post('/api/v1/auth/refresh', [])->assertStatus(422)->assertExactJson(['error' => ['code' => 'validation_error']]);
        $this->get('/api/v1/missing')->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found']]);
        $this->get('/api/v1/auth/login')->assertStatus(405)->assertExactJson(['error' => ['code' => 'method_not_allowed']]);
    }

    public function test_login_limits_count_only_validated_failures_and_isolate_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->assertSame(15, Redis::connection('cache')->client()->getDbNum());
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email])->assertStatus(422);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->login($user); // Success clears the account counter, and consumes neither limit.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertExactJson(['error' => ['code' => 'rate_limited']]);
        $this->login($other);
        // A fresh limiter instance reads the shared Redis counters (not process memory).
        $key = 'api-login:account:'.hash('sha256', strtolower($user->email).'|127.0.0.1');
        $limiter = new \Illuminate\Cache\RateLimiter(Cache::store('redis'));
        $this->assertSame(5, (int) $limiter->attempts($key));
        $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        foreach (['APP_ENV'=>'testing','CACHE_STORE'=>'redis','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $name=>$value) {
            putenv($name.'='.$value); $_ENV[$name]=$value; $_SERVER[$name]=$value;
        }
        if (file_exists('bootstrap/cache/config.php')) { exit(10); }
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.redis.cache.url'=>null,'database.redis.cache.database'=>15]);
        if (\Illuminate\Support\Facades\Redis::connection('cache')->client()->getDbNum() !== 15) { exit(11); }
        $limiter = new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store('redis'));
        echo $limiter->attempts(trim(fgets(STDIN)));
        PHP;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], $key."\n");
        fclose($pipes[0]);
        $count = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame('', $errors);
        $this->assertSame('5', $count);

        for ($i = 0; $i < 21; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'missing'.$i.'@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_bearer_cannot_fall_back_to_web_identity_and_no_api_endpoint_starts_a_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $pair = app(ApiTokenService::class)->issue($user);
        $response = $this->me($pair['access_token'])->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(app('session')->driver()->isStarted());
        $response = $this->refreshToken($pair['refresh_token'])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(app('session')->driver()->isStarted());
    }

    public function test_story_read_and_existing_boost_toggle_use_bearer_identity_and_current_policy(): void
    {
        $user = User::factory()->create();
        $pair = $this->login($user);
        $story = Story::create(['title' => 'Fixture Story', 'status' => 'active']);
        $article = Article::create(['title' => 'Fixture article', 'slug' => 'fixture-article', 'original_url' => 'https://example.test/article', 'published_at' => now()]);
        $this->me($pair['access_token'])->assertOk();
        $response = $this->getJson('/api/v1/stories/'.$story->id)->assertOk()->assertJsonPath('story.title', 'Fixture Story')
            ->assertJsonPath('sections.timeline.state', 'pending');
        $this->assertSame([], $response->headers->getCookies());
        $this->getJson('/api/v1/stories/not-a-uuid')->assertNotFound();
        $this->getJson('/api/v1/stories/'.Str::uuid7())->assertNotFound();
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'added', 'count' => 1]);
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'removed', 'count' => 0]);
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => 'missing'])->assertStatus(422);
        $user->update(['email_verified_at' => null]);
        $this->me($pair['access_token'])->assertOk()->assertJsonPath('user.email_verified', false);
        $this->getJson('/api/v1/stories/'.$story->id)->assertOk();
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => $article->id])->assertForbidden()->assertJsonPath('error.code', 'email_unverified');
        $user->update(['email_verified_at' => now()]);
        Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'temporary_ban', 'reason' => 'Fixture', 'is_active' => true]);
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => $article->id])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        // The bearer layer normally rejects the revoked family first. Simulate a ban
        // visible only at action time to exercise the endpoint's own 403 guard.
        $this->mock(ApiTokenService::class, fn ($mock) => $mock->shouldReceive('authenticate')
            ->once()->andReturn(['user' => $user->fresh(), 'family' => 'fixture']));
        $this->postJson('/api/v1/boosts/toggle', ['article_id' => $article->id])->assertForbidden()
            ->assertJsonPath('error.code', 'interaction_forbidden');

        $stream = Redis::connection('nosql')->client()->xRange(InteractionStream::STREAM, '-', '+');
        $events = array_map(fn ($entry) => json_decode($entry['event'], true, flags: JSON_THROW_ON_ERROR), array_values($stream));
        $this->assertSame(['BOOSTED', 'UNBOOSTED'], array_column($events, 'type'));
        $this->assertSame([$user->id, $user->id], array_column($events, 'user_id'));
        $this->assertSame([$article->id, $article->id], array_column($events, 'article_id'));
        $this->assertFalse(app('session')->driver()->isStarted());
    }

    public function test_unauthenticated_non_auth_endpoints_cannot_use_web_session(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/stories/'.Str::uuid7())->assertUnauthorized();
        $this->postJson('/api/v1/boosts/toggle', [])->assertUnauthorized();
    }

    public function test_raw_tokens_never_appear_in_redis_keys_values_or_flow_logs_and_actual_ttls_are_bounded(): void
    {
        config(['nosql.api_tokens.access_ttl_seconds' => 17, 'nosql.api_tokens.refresh_ttl_seconds' => 31]);
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $first = $this->login();
        $second = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $this->me($first['access_token'])->assertOk();
        $redis = Redis::connection('nosql');
        $this->assertSame(15, $redis->client()->getDbNum());
        foreach (['access' => 77, 'refresh' => 91] as $kind => $expectedTtl) {
            foreach ([$first, $second] as $pair) {
                $ttl = $redis->ttl('api-token:'.$kind.':'.hash('sha256', $pair[$kind.'_token']));
                $this->assertGreaterThanOrEqual($expectedTtl - 15, $ttl);
                $this->assertLessThanOrEqual($expectedTtl, $ttl);
            }
        }
        $this->refreshToken($first['refresh_token'])->assertUnauthorized();
        $this->me($second['access_token'])->assertUnauthorized();
        $this->refreshToken($second['refresh_token'])->assertUnauthorized();
        $prefix = $redis->client()->getOption(\Redis::OPT_PREFIX);
        $contents = '';
        foreach ($redis->keys('*') as $key) {
            $local = substr($key, strlen($prefix));
            $contents .= $key.$redis->dump($local);
        }
        $contents .= json_encode($logs->getRecords());
        foreach ([$first['access_token'], $first['refresh_token'], $second['access_token'], $second['refresh_token']] as $raw) {
            $this->assertFalse(str_contains($contents, $raw), 'Raw token leaked into Redis or logs');
        }
        Log::getLogger()->popHandler();
        // Expiry diagnostics are bounded: once the metadata has aged out, it is unknown.
        $redis->del('api-token:access:'.hash('sha256', $first['access_token']));
        $this->me($first['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_access_token');
    }

    public function test_family_index_prunes_expired_members_and_preserves_longest_token_horizon(): void
    {
        config(['nosql.api_tokens.access_ttl_seconds' => 300, 'nosql.api_tokens.refresh_ttl_seconds' => 120]);
        $user = User::factory()->create();
        $service = app(ApiTokenService::class);
        $first = $service->issue($user);
        $redis = Redis::connection('nosql');
        $index = 'api-token:user:'.$user->id;
        $redis->zadd($index, time() - 1, 'expired-fixture-family');
        config(['nosql.api_tokens.access_ttl_seconds' => 60, 'nosql.api_tokens.refresh_ttl_seconds' => 60]);
        $next = $service->refresh($first['refresh_token']);
        $this->assertSame(1, $redis->zcard($index));
        $this->assertGreaterThanOrEqual(330, $redis->ttl($index));
        $this->assertLessThanOrEqual(360, $redis->ttl($index));
        $secondFamily = $service->issue($user);
        $this->assertSame(2, $redis->zcard($index));
        $this->assertGreaterThanOrEqual(330, $redis->ttl($index));
        $service->revokeAllForUser((string) $user->id);
        $this->assertSame(0, $redis->exists($index));
        foreach ([$first, $next, $secondFamily] as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
    }

    public static function invalidTtls(): array
    {
        return array_merge(...array_map(fn ($kind) => array_map(fn ($ttl) => [$kind, $ttl], [0, -1, '1.5', 'invalid', null, true, 1.0, 31536001]), ['access', 'refresh']));
    }

    #[DataProvider('invalidTtls')]
    public function test_invalid_ttl_configuration_fails_closed_without_issuing_tokens(string $kind, mixed $ttl): void
    {
        config(['nosql.api_tokens.'.$kind.'_ttl_seconds' => $ttl]);
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(500)->assertExactJson(['error' => ['code' => 'server_error']]);
        $this->assertSame([], Redis::connection('nosql')->keys('api-token:*'));
    }

    public function test_login_timing_is_comparable_for_unknown_email_and_wrong_password(): void
    {
        Hash::driver()->setRounds(8);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $unknown = fake()->unique()->safeEmail();
        $durations = [[], []];
        for ($sample = 0; $sample < 3; $sample++) {
            foreach ([$user->email, $unknown] as $branch => $email) {
                RateLimiter::clear('api-login:'.hash('sha256', '127.0.0.1'));
                $start = hrtime(true);
                $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnauthorized();
                $durations[$branch][] = (hrtime(true) - $start) / 1e9;
            }
        }
        sort($durations[0]);
        sort($durations[1]);
        $this->assertGreaterThan(0.02, $durations[0][1]);
        $this->assertGreaterThan(0.02, $durations[1][1]);
        $ratio = $durations[1][1] / $durations[0][1];
        $this->assertGreaterThan(0.6, $ratio);
        $this->assertLessThan(1.7, $ratio);
    }

    public function test_false_eval_result_fails_closed_for_every_module_operation(): void
    {
        $user = User::factory()->create();
        $tokens = app(ApiTokenService::class);
        $pair = $tokens->issue($user);
        $family = $tokens->authenticate($pair['access_token'])['family'];
        $manager = Redis::getFacadeRoot();
        $client = Redis::connection('nosql')->client();
        $proxy = new class($client) extends PhpRedisConnection
        {
            public function command($method, array $parameters = [])
            {
                return $method === 'eval' ? false : parent::command($method, $parameters);
            }
        };
        Redis::shouldReceive('connection')->with('nosql')->andReturn($proxy);
        try {
            $operations = [
                fn () => $tokens->issue($user),
                fn () => $tokens->authenticate($pair['access_token']),
                fn () => $tokens->refresh($pair['refresh_token']),
                fn () => $tokens->revokeFamily($family),
            ];
            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('False EVAL must fail closed');
                } catch (TokenException $e) {
                    $this->assertSame('service_unavailable', $e->getMessage());
                    $this->assertSame(503, $e->status);
                    $this->assertNull($e->getPrevious());
                }
            }
            $this->me($pair['access_token'])->assertStatus(503)->assertExactJson(['error' => ['code' => 'service_unavailable']]);
        } finally {
            Redis::swap($manager);
        }
        $this->me($pair['access_token'])->assertOk();
    }

    public function test_real_redis_type_error_cannot_consume_refresh_before_rotation(): void
    {
        $user = User::factory()->create();
        $pair = $this->login($user);
        $redis = Redis::connection('nosql');
        $index = 'api-token:user:'.$user->id;
        $redis->del($index);
        $redis->set($index, 'invalid-type-fixture');
        $this->refreshToken($pair['refresh_token'])->assertStatus(503)->assertExactJson(['error' => ['code' => 'service_unavailable']]);
        $this->me($pair['access_token'])->assertOk();
        $redis->del($index);
        $this->refreshToken($pair['refresh_token'])->assertOk();
    }

    public function test_rotated_marker_keeps_original_lifetime_when_new_refresh_lifetime_is_shorter(): void
    {
        config(['nosql.api_tokens.access_ttl_seconds' => 120, 'nosql.api_tokens.refresh_ttl_seconds' => 120]);
        $first = $this->login();
        config(['nosql.api_tokens.refresh_ttl_seconds' => 60]);
        $second = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $this->expireToken('refresh', $second['refresh_token']);
        $this->refreshToken($second['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'refresh_token_expired');
        $this->me($second['access_token'])->assertOk();
        $this->refreshToken($first['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'refresh_token_reused');
        $this->me($second['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
    }

    public function test_concurrent_http_refresh_requests_allow_at_most_one_success_and_revoke_on_reuse(): void
    {
        $pair = $this->login();
        // Independent PHP processes/connections; credentials travel only through pipes.
        // Each worker boots the actual HTTP kernel with a fresh isolated SQLite schema.
        $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','MONGO_DB'=>'vietfeed_test','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
        }
        if (file_exists(getcwd().'/bootstrap/cache/config.php')) { exit(10); }
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config('database.connections.mongodb.database') !== 'vietfeed_test' || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') { exit(11); }
        foreach (['nosql','default','cache'] as $name) { config(['database.redis.'.$name.'.url'=>null,'database.redis.'.$name.'.database'=>15]); }
        foreach (['nosql','default','cache'] as $name) { if (\Illuminate\Support\Facades\Redis::connection($name)->client()->getDbNum() !== 15) { exit(12); } }
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
        echo "ready\n"; fflush(STDOUT);
        $input = json_decode(fgets(STDIN), true);
        $request = \Illuminate\Http\Request::create('/api/v1/auth/refresh', 'POST', [], [], [], ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'], json_encode($input));
        $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
        echo json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true)]);
        PHP;
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], json_encode(['refresh_token' => $pair['refresh_token']])."\n");
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), 'Concurrent worker must exit cleanly');
                $this->assertTrue($errors === '', 'Concurrent worker stderr must be empty');
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 401], $statuses);
            foreach ($results as $result) {
                if ($result['status'] === 200) {
                    $this->me($result['body']['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
                    $this->refreshToken($result['body']['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
                } else {
                    $this->assertSame('refresh_token_reused', $result['body']['error']['code']);
                }
            }
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
        $this->me($pair['access_token'])->assertUnauthorized();
    }

    public function test_unverified_login_is_allowed_and_legacy_web_api_routes_keep_their_web_middleware(): void
    {
        $user = User::factory()->unverified()->create();
        $pair = $this->login($user);
        $this->me($pair['access_token'])->assertOk()->assertJsonPath('user.email_verified', false);
        foreach (['/api/live-search', '/api/articles', '/api/ticker'] as $uri) {
            $request = Request::create($uri, 'GET');
            $route = app('router')->getRoutes()->match($request);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertNotContains(ApiBearer::class, $route->gatherMiddleware());
        }
        $this->getJson('/api/live-search?q=ab')->assertOk();
        $this->getJson('/api/articles')->assertOk();
    }

    public function test_no_store_and_no_cookies_on_issuing_responses_with_real_redis_web_session_driver(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        $user = User::factory()->create();
        $pair = $this->login($user);
        $response = $this->me($pair['access_token'])->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(app('session')->driver()->isStarted());
        $rotated = $this->refreshToken($pair['refresh_token'])->assertOk();
        $this->assertSame([], $rotated->headers->getCookies());
        $this->assertStringContainsString('no-store', $rotated->headers->get('Cache-Control'));
        $this->assertFalse(app('session')->driver()->isStarted());
        $prefix = Redis::connection('nosql')->client()->getOption(\Redis::OPT_PREFIX);
        foreach (Redis::connection('nosql')->keys('*') as $key) {
            $this->assertStringStartsWith($prefix.'api-token:', $key);
        }
    }

    private function expireToken(string $kind, string $token): void
    {
        $redis = Redis::connection('nosql');
        $key = 'api-token:'.$kind.':'.hash('sha256', $token);
        $value = json_decode($redis->get($key), true);
        $value['expires'] = (int) $redis->time()[0] - 1;
        $redis->set($key, json_encode($value), 'EX', 180);
    }

    public function test_login_snapshot_cannot_escape_password_change_or_ban_committed_before_issue(): void
    {
        foreach (['password', 'permanent_ban', 'temporary_ban'] as $action) {
            $user = User::factory()->create();
            $tokens = new class($action) extends ApiTokenService
            {
                public function __construct(private string $action) {}

                public function issue(User $snapshot): array
                {
                    // Called by the real controller only after password verification.
                    $current = User::findOrFail($snapshot->id);
                    if ($this->action === 'password') {
                        $current->update(['password' => 'changed-password']);
                    } else {
                        Sanction::create(['user_id' => $current->id, 'admin_id' => $current->id,
                            'type' => $this->action, 'reason' => 'Fixture', 'is_active' => true]);
                    }

                    return parent::issue($snapshot);
                }
            };
            $this->app->instance(ApiTokenService::class, $tokens);
            $pair = $this->login($user);
            $this->assertSame($action === 'password' ? 1 : 2, User::findOrFail($user->id)->api_token_epoch);
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
    }

    public function test_login_between_pre_bump_and_sql_commit_cannot_survive_cleanup_or_admin_lift(): void
    {
        foreach (['temporary_ban', 'permanent_ban'] as $type) {
            $user = User::factory()->create();
            $admin = User::factory()->create(['role' => 'admin']);
            $tokens = new class extends ApiTokenService
            {
                public function issue(User $snapshot): array
                {
                    // Pause the real login after credentials, epoch and SQL ban reads.
                    \Fiber::suspend($snapshot);

                    return parent::issue($snapshot);
                }
            };
            $this->app->instance(ApiTokenService::class, $tokens);
            $login = new \Fiber(fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']));
            new Sanction;
            $dispatcher = Sanction::getEventDispatcher();
            Sanction::setEventDispatcher(clone $dispatcher);
            Sanction::creating(function () use ($login) {
                // Eloquent creating runs after saving, immediately before SQL INSERT.
                $snapshot = $login->start();
                $this->assertSame(1, $snapshot->api_token_epoch);
                $this->assertFalse($snapshot->isBanned());
            });
            try {
                $this->actingAs($admin)->post('/admin/sanctions', ['user_id' => $user->id, 'type' => $type, 'reason' => 'Fixture'])
                    ->assertRedirect(route('admin.sanctions.index'))->assertSessionHas('success')->assertSessionMissing('error');
            } finally {
                Sanction::setEventDispatcher($dispatcher);
            }
            $this->assertSame([], Redis::connection('nosql')->zrange('api-token:user:'.$user->id, 0, -1));
            $login->resume(); // Redis issuance lands only AFTER the saved hook's cleanup.
            $pair = $login->getReturn()->assertOk()->json();
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $sanction = $user->sanctions()->sole();
            Sanction::where('id', $sanction->id)->update(['appeal_status' => 'pending', 'appeal_message' => 'Fixture appeal']); // Gate 9a: only pending appeals can be decided
            $this->actingAs($admin)->patch('/admin/sanctions/'.$sanction->id, ['appeal_status' => 'approved'])
                ->assertRedirect()->assertSessionHas('success');
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->assertSame(3, $user->fresh()->api_token_epoch);
            $this->app->instance(ApiTokenService::class, new ApiTokenService);
            $fresh = $this->login($user);
            $this->me($fresh['access_token'])->assertOk();
        }
    }

    public function test_appeal_denial_does_not_touch_mongo_when_epoch_updates_would_fail(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $admin->id, 'type' => 'temporary_ban',
            'reason' => 'Fixture', 'is_active' => true, 'expires_at' => now()->addDay()]);
        $this->assertSame(2, $user->fresh()->api_token_epoch);
        User::where('_id', $user->id)->update(['api_token_epoch' => 'forced-increment-failure']);
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            Sanction::where('id', $ban->id)->update(['appeal_status' => 'pending', 'appeal_message' => 'Fixture appeal']); // Gate 9a: only pending appeals can be decided
            $this->actingAs($admin)->patch('/admin/sanctions/'.$ban->id, ['appeal_status' => 'denied'])
                ->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');
            $this->assertSame('denied', $ban->fresh()->appeal_status);
            $this->assertTrue($ban->fresh()->is_active);
            $this->assertSame('forced-increment-failure', DB::connection('mongodb')->table('users')->where('_id', $user->id)->first()->api_token_epoch);
            $this->assertFalse($logs->hasWarningRecords());
        } finally {
            Log::getLogger()->popHandler();
        }
    }

    public function test_second_bump_failure_preserves_admin_success_and_blocks_racing_tokens_until_lift(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $tokens = new class extends ApiTokenService
        {
            public function issue(User $snapshot): array
            {
                \Fiber::suspend($snapshot);

                return parent::issue($snapshot);
            }
        };
        $this->app->instance(ApiTokenService::class, $tokens);
        $login = new \Fiber(fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']));
        new Sanction;
        $dispatcher = Sanction::getEventDispatcher();
        Sanction::setEventDispatcher(clone $dispatcher);
        Sanction::creating(function () use ($login) {
            $this->assertSame(1, $login->start()->api_token_epoch);
        });
        Sanction::created(function () use ($user) {
            // Real Mongo increment failure, only after SQL has persisted the ban.
            User::where('_id', $user->id)->update(['api_token_epoch' => 'private-mongo-diagnostic']);
        });
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            $this->actingAs($admin)->post('/admin/sanctions', ['user_id' => $user->id, 'type' => 'temporary_ban', 'reason' => 'Fixture'])
                ->assertRedirect(route('admin.sanctions.index'))->assertSessionHas('success')->assertSessionMissing('error');
        } finally {
            Sanction::setEventDispatcher($dispatcher);
            Log::getLogger()->popHandler();
        }
        $ban = $user->sanctions()->sole();
        $this->assertTrue($ban->isCurrentlyActive());
        $warning = $logs->getRecords()[0];
        $this->assertSame('Sanction token epoch update failed', $warning->message);
        $this->assertSame(['operation' => 'ban_saved', 'exception_type' => BulkWriteException::class], $warning->context);
        $this->assertStringNotContainsString('private-mongo-diagnostic', json_encode($logs->getRecords()));
        // Recover Mongo without advancing the epoch, then land the paused Redis write.
        User::where('_id', $user->id)->update(['api_token_epoch' => 1]);
        $login->resume();
        $pair = $login->getReturn()->assertOk()->json();
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        Sanction::where('id', $ban->id)->update(['appeal_status' => 'pending', 'appeal_message' => 'Fixture appeal']); // Gate 9a: only pending appeals can be decided
        $this->actingAs($admin)->patch('/admin/sanctions/'.$ban->id, ['appeal_status' => 'approved'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, $user->fresh()->api_token_epoch);
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
    }

    public function test_admin_lift_succeeds_and_logs_safely_when_mongo_epoch_bump_fails(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $admin->id, 'type' => 'permanent_ban',
            'reason' => 'Fixture', 'is_active' => true]);
        User::where('_id', $user->id)->update(['api_token_epoch' => 'private-mongo-diagnostic']);
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            Sanction::where('id', $ban->id)->update(['appeal_status' => 'pending', 'appeal_message' => 'Fixture appeal']); // Gate 9a: only pending appeals can be decided
            $this->actingAs($admin)->patch('/admin/sanctions/'.$ban->id, ['appeal_status' => 'approved'])
                ->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');
        } finally {
            Log::getLogger()->popHandler();
        }
        $this->assertFalse($ban->fresh()->is_active);
        $warning = $logs->getRecords()[0];
        $this->assertSame('Sanction token epoch update failed', $warning->message);
        $this->assertSame(['operation' => 'ban_lifted', 'exception_type' => BulkWriteException::class], $warning->context);
        $this->assertStringNotContainsString('private-mongo-diagnostic', json_encode($logs->getRecords()));
    }

    public function test_admin_deleting_an_effective_ban_invalidates_families_issued_while_banned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['temporary_ban', 'permanent_ban'] as $type) {
            $user = User::factory()->create();
            $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $admin->id, 'type' => $type,
                'reason' => 'Fixture', 'is_active' => true, 'expires_at' => now()->addDay()]);
            $pair = app(ApiTokenService::class)->issue($user->fresh());
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
            $this->actingAs($admin)->post('/admin/sanctions/'.$ban->id.'/revoke') // Gate 9a: revoke replaces delete
                ->assertRedirect(route('admin.sanctions.index'))->assertSessionHas('success')->assertSessionMissing('error');
            $this->assertNotNull($ban->fresh()->revoked_at);
            $this->assertSame(3, $user->fresh()->api_token_epoch);
            $this->assertSame([], Redis::connection('nosql')->zrange('api-token:user:'.$user->id, 0, -1));
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->login($user);
        }
    }

    public function test_admin_ban_delete_succeeds_and_cleans_redis_when_epoch_bump_fails(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $admin->id, 'type' => 'permanent_ban',
            'reason' => 'Fixture', 'is_active' => true]);
        $pair = app(ApiTokenService::class)->issue($user->fresh());
        User::where('_id', $user->id)->update(['api_token_epoch' => 'private-mongo-diagnostic']);
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            $this->actingAs($admin)->post('/admin/sanctions/'.$ban->id.'/revoke') // Gate 9a: revoke replaces delete
                ->assertRedirect(route('admin.sanctions.index'))->assertSessionHas('success')->assertSessionMissing('error');
        } finally {
            Log::getLogger()->popHandler();
        }
        $this->assertNotNull($ban->fresh()->revoked_at);
        $warning = $logs->getRecords()[0];
        $this->assertSame('Sanction token epoch update failed', $warning->message);
        $this->assertSame(['operation' => 'ban_lifted', 'exception_type' => BulkWriteException::class], $warning->context);
        $this->assertStringNotContainsString('private-mongo-diagnostic', json_encode($logs->getRecords()));
        $this->assertSame([], Redis::connection('nosql')->zrange('api-token:user:'.$user->id, 0, -1));
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
    }

    public function test_sanction_user_reassignment_throws_without_persisting_any_changes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $sanction = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'warning',
            'reason' => 'Original reason', 'is_active' => true]);
        $original = $sanction->fresh()->getRawOriginal();
        try {
            $sanction->update(['user_id' => $other->id, 'reason' => 'Changed reason']);
            $this->fail('Sanction user reassignment must be rejected.');
        } catch (\LogicException $error) {
            $this->assertSame('Sanction user_id cannot be changed after creation.', $error->getMessage());
        }
        $this->assertEquals($original, $sanction->fresh()->getRawOriginal()); // Gate 9b: Mongo dates hydrate as new Carbon instances
        $this->assertSame(0, $user->fresh()->api_token_epoch);
        $this->assertSame(0, $other->fresh()->api_token_epoch);
    }

    public function test_moving_either_ban_expiry_to_the_past_invalidates_families_issued_during_the_ban(): void
    {
        foreach (['temporary_ban', 'permanent_ban'] as $type) {
            $user = User::factory()->create();
            $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => $type,
                'reason' => 'Fixture', 'is_active' => true, 'expires_at' => now()->addDay()]);
            // Simulate a family landing during the ban, after creation cleanup.
            $pair = app(ApiTokenService::class)->issue($user->fresh());
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
            $ban->update(['expires_at' => now()->subDay()]);
            $this->assertSame(3, $user->fresh()->api_token_epoch);
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->login($user);
        }
    }

    public function test_newer_warning_does_not_mask_active_ban_for_api_or_web(): void
    {
        $user = User::factory()->create();
        $pair = $this->login($user);
        Sanction::withoutEvents(function () use ($user) {
            $ban = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'permanent_ban', 'reason' => 'Fixture', 'is_active' => true]);
            $ban->forceFill(['created_at' => now()->subDay()])->saveQuietly();
            Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'warning', 'reason' => 'Fixture', 'is_active' => true]);
        });
        $this->assertSame('warning', $user->activeSanction()->type);
        $this->assertTrue($user->isBanned());
        $this->assertTrue($user->isPermanentlyBanned());
        $this->assertTrue($user->isMuted());
        $this->assertFalse($user->canComment());
        $this->assertFalse($user->canBoost());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'account_unavailable');
        $this->actingAs($user)->get('/profile')->assertRedirect(route('sanctions.banned'));
    }

    public function test_web_security_actions_complete_when_token_redis_is_down(): void
    {
        foreach (['password', 'delete', 'ban'] as $action) {
            $user = User::factory()->create();
            $pair = $this->login($user);
            $next = $this->refreshToken($pair['refresh_token'])->assertOk()->json();
            $admin = User::factory()->create(['role' => 'admin']);
            $manager = Redis::getFacadeRoot();
            $logs = new TestHandler;
            Log::getLogger()->pushHandler($logs);
            Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RuntimeException('sensitive-driver-diagnostic'));
            try {
                if ($action === 'password') {
                    $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])
                        ->assertSessionHasNoErrors()->assertRedirect();
                    $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
                    $this->assertSame(1, $user->fresh()->api_token_epoch);
                } elseif ($action === 'delete') {
                    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');
                    $this->assertNull(User::find($user->id));
                } else {
                    $this->actingAs($admin)->post('/admin/sanctions', ['user_id' => $user->id, 'type' => 'permanent_ban', 'reason' => 'Fixture'])
                        ->assertSessionHasNoErrors()->assertRedirect(route('admin.sanctions.index'));
                    $this->assertTrue($user->fresh()->isPermanentlyBanned());
                    $this->assertSame(2, $user->fresh()->api_token_epoch);
                }
            } finally {
                Redis::swap($manager);
                Log::getLogger()->popHandler();
            }
            $this->assertTrue($logs->hasWarningRecords());
            $this->assertStringNotContainsString('sensitive-driver-diagnostic', json_encode($logs->getRecords()));
            $expected = $action === 'delete' ? 'account_unavailable' : 'token_family_revoked';
            foreach ([$pair, $next] as $generation) {
                $this->me($generation['access_token'])->assertUnauthorized()->assertJsonPath('error.code', $expected);
                // The original rotated refresh token is already consumed; test the live one.
            }
            $this->refreshToken($next['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', $expected);
        }
    }

    public static function exceptionMappings(): array
    {
        return [
            [new HttpException(401), 401, 'unauthenticated'],
            [new HttpException(403), 403, 'forbidden'],
            [new HttpException(404), 404, 'not_found'],
            [new HttpException(405), 405, 'method_not_allowed'],
            [new HttpException(429, '', null, ['Retry-After' => '12']), 429, 'rate_limited'],
            [new AuthorizationException, 403, 'forbidden'],
            [new ModelNotFoundException, 404, 'not_found'],
            [new \RuntimeException('private-exception-message'), 500, 'server_error'],
        ];
    }

    #[DataProvider('exceptionMappings')]
    public function test_exception_status_mapping_and_safe_reporting(\Throwable $error, int $status, string $code): void
    {
        Route::post('/api/v1/test-exception', fn () => throw $error);
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            $response = $this->withHeader('Authorization', 'Bearer private-header')
                ->postJson('/api/v1/test-exception', ['password' => 'private-input'])
                ->assertStatus($status)->assertExactJson(['error' => ['code' => $code]]);
            if ($status === 429) {
                $response->assertHeader('Retry-After', '12');
            }
            if ($status === 500) {
                $this->assertTrue($logs->hasErrorRecords());
                $records = json_encode($logs->getRecords());
                foreach (['private-input', 'private-header', 'private-exception-message'] as $secret) {
                    $this->assertStringNotContainsString($secret, $records);
                }
                $this->assertStringContainsString('RuntimeException', $records);
            }
        } finally {
            Log::getLogger()->popHandler();
        }
    }

    public function test_validation_values_are_hidden_and_non_api_reporting_and_rendering_are_unchanged(): void
    {
        Route::post('/api/v1/test-validation', function () {
            throw ValidationException::withMessages(['private-field' => ['private-value']]);
        });
        $this->postJson('/api/v1/test-validation')->assertStatus(422)->assertExactJson(['error' => ['code' => 'validation_error']]);
        Route::get('/test-web-forbidden', fn () => abort(403));
        $this->get('/test-web-forbidden')->assertForbidden()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->getJson('/test-web-forbidden')->assertForbidden()->assertJsonPath('message', '');
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        try {
            app()->instance('request', Request::create('/test-web-report'));
            report(new \RuntimeException('web-report-marker'));
            $this->assertTrue($logs->hasErrorThatContains('web-report-marker'));
            app()->forgetInstance('request');
            report(new \RuntimeException('console-report-marker'));
            $this->assertTrue($logs->hasErrorThatContains('console-report-marker'));
        } finally {
            app()->instance('request', Request::create('/'));
            Log::getLogger()->popHandler();
        }
    }

    public function test_temporary_ban_and_explicit_account_revoke_reject_all_generations_after_ban_lift(): void
    {
        foreach (['temporary_ban', 'explicit'] as $action) {
            $user = User::factory()->create();
            $first = $this->login($user);
            $next = $this->refreshToken($first['refresh_token'])->assertOk()->json();
            $other = $this->login($user);
            $redis = Redis::connection('nosql');
            // Retain the families to prove Mongo epoch is sufficient independently of cleanup.
            $families = [];
            foreach ($redis->zrange('api-token:user:'.$user->id, 0, -1) as $family) {
                $families[$family] = $redis->get('api-token:family:'.$family);
            }
            if ($action === 'explicit') {
                app(ApiTokenService::class)->revokeAllForUser((string) $user->id);
            } else {
                $sanction = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'temporary_ban',
                    'reason' => 'Fixture', 'is_active' => true]);
                $sanction->update(['is_active' => false]);
            }
            foreach ($families as $family => $value) {
                $redis->set('api-token:family:'.$family, $value, 'EX', 180);
            }
            foreach ([$first, $next, $other] as $pair) {
                $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            }
            $this->refreshToken($next['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->login($user); // fresh epoch can issue a working family
        }
    }

    public function test_password_save_increments_stored_epoch_even_from_a_stale_model_snapshot(): void
    {
        $user = User::factory()->create();
        $stale = User::findOrFail($user->id);
        $user->update(['password' => 'first-password']);
        $this->assertSame(1, $user->fresh()->api_token_epoch);
        $stale->update(['password' => 'second-password']);
        $this->assertSame(2, $stale->fresh()->api_token_epoch);
        $this->assertTrue(Hash::check('second-password', $stale->fresh()->password));
    }

    public function test_admin_ban_mongo_failure_aborts_before_sql_and_preserves_related_state_and_tokens(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->login($user);
        $next = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $article = Article::create(['title' => 'Fixture', 'slug' => 'ban-failure', 'original_url' => 'https://example.test/ban']);
        $comment = Comment::create(['user_id' => $user->id, 'article_id' => $article->id, 'body' => 'Fixture']);
        $report = Report::create(['reporter_id' => $admin->id, 'comment_id' => $comment->id, 'reason' => 'spam', 'status' => 'pending']);
        $beforeComment = $comment->fresh()->getAttributes();
        $beforeReport = $report->fresh()->getAttributes();
        $redis = Redis::connection('nosql');
        $beforeTokens = [];
        foreach ($redis->keys('*') as $key) {
            $prefix = $redis->client()->getOption(\Redis::OPT_PREFIX);
            $logical = substr($key, strlen($prefix));
            $beforeTokens[$logical] = $redis->dump($logical);
        }
        // A real Mongo $inc error, without stopping the store or changing its config.
        User::where('_id', $user->id)->update(['api_token_epoch' => 'forced-increment-failure']);
        $this->actingAs($admin)->from('/admin/sanctions')->post('/admin/sanctions', [
            'user_id' => $user->id, 'type' => 'permanent_ban', 'reason' => 'Fixture',
            'report_id' => $report->id, 'comment_id' => $comment->id,
        ])->assertRedirect('/admin/sanctions')->assertSessionHas('error', 'Không thể áp dụng biện pháp xử lý. Vui lòng thử lại.')->assertSessionMissing('success');
        $this->assertSame(0, Sanction::count());
        $this->assertEquals($beforeComment, $comment->fresh()->getAttributes()); // Gate 9.5: comment on Mongo, same reason as below
        $this->assertEquals($beforeReport, $report->fresh()->getAttributes()); // Gate 9b: Mongo dates hydrate as new Carbon instances
        $this->assertSame(count($beforeTokens), count($redis->keys('*')));
        foreach ($beforeTokens as $key => $value) {
            $this->assertSame($value, $redis->dump($key));
        }
        User::where('_id', $user->id)->update(['api_token_epoch' => 0]);
        $this->me($first['access_token'])->assertOk();
        $this->me($next['access_token'])->assertOk();
        $this->refreshToken($next['refresh_token'])->assertOk();
    }

    public function test_admin_ban_sql_failure_only_revokes_existing_families(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->login($user);
        $next = $this->refreshToken($first['refresh_token'])->assertOk()->json();
        $other = $this->login($user);
        // Gate 9b: sanctions live on MongoDB; a collection validator rejects the insert (was an SQL trigger).
        $mongo = DB::connection('mongodb')->getMongoDB();
        if (iterator_count($mongo->listCollections(['filter' => ['name' => 'sanctions']])) === 0) {
            $mongo->createCollection('sanctions');
        }
        $mongo->command(['collMod' => 'sanctions', 'validator' => ['$expr' => false], 'validationAction' => 'error']);
        try {
            $this->actingAs($admin)->from('/admin/sanctions')->post('/admin/sanctions', [
                'user_id' => $user->id, 'type' => 'temporary_ban', 'reason' => 'Fixture',
            ])->assertRedirect('/admin/sanctions')->assertSessionHas('error', 'Không thể áp dụng biện pháp xử lý. Vui lòng thử lại.')->assertSessionMissing('success');
        } finally {
            $mongo->command(['collMod' => 'sanctions', 'validator' => (object) []]);
        }
        $this->assertSame(0, Sanction::count());
        $this->assertSame(1, $user->fresh()->api_token_epoch);
        foreach ([$first, $next, $other] as $pair) {
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
        foreach ([$next, $other] as $pair) {
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
        $this->login($user);
    }

    public function test_sanction_updates_revoke_when_the_result_is_an_effective_ban(): void
    {
        foreach (['type', 'is_active', 'expires_at'] as $field) {
            $user = User::factory()->create();
            $attributes = ['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'temporary_ban',
                'reason' => 'Fixture', 'is_active' => true, 'expires_at' => now()->addDay()];
            $attributes[$field] = match ($field) {
                'type' => 'warning', 'is_active' => false, 'expires_at' => now()->subDay(),
            };
            $sanction = Sanction::create($attributes);
            $this->assertSame(0, $user->fresh()->api_token_epoch);
            $pair = $this->login($user);
            $sanction->update([$field => match ($field) {
                'type' => 'permanent_ban', 'is_active' => true, 'expires_at' => now()->addDay(),
            }]);
            $this->assertSame(2, $user->fresh()->api_token_epoch);
            $sanction->update(['is_active' => false]);
            $this->assertSame(3, $user->fresh()->api_token_epoch);
            $this->me($pair['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($pair['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
    }

    public function test_article_comment_block_displays_older_mute_instead_of_newer_warning(): void
    {
        $user = User::factory()->create();
        $mute = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'mute',
            'reason' => 'Actual mute reason', 'is_active' => true, 'expires_at' => now()->addDay()]);
        $mute->forceFill(['created_at' => now()->subDay()])->save();
        Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'warning',
            'reason' => 'Unrelated warning reason', 'is_active' => true]);
        $category = Category::create(['name' => 'Fixture', 'slug' => 'fixture', 'is_active' => true]);
        $source = Source::create(['name' => 'Fixture', 'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss', 'category_id' => $category->id, 'is_active' => true]);
        $article = Article::create(['title' => 'Fixture', 'slug' => 'muted-comments', 'original_url' => 'https://example.test/comments',
            'category_id' => $category->id, 'source_id' => $source->id, 'published_at' => now()]);
        $this->assertSame('warning', $user->activeSanction()->type);
        $this->actingAs($user)->get('/articles/'.$article->slug)->assertOk()
            ->assertSee('Actual mute reason')->assertSee('Cấm bình luận')
            ->assertSee($mute->expires_at->format('d/m/Y H:i'))->assertDontSee('Unrelated warning reason');
        $this->assertSame($mute->id, $user->blockingCommentSanction()->id);
    }

    public function test_article_uses_one_blocking_sanction_snapshot_and_allows_comments_when_it_is_null(): void
    {
        $user = User::factory()->create();
        $mute = Sanction::create(['user_id' => $user->id, 'admin_id' => $user->id, 'type' => 'mute',
            'reason' => 'Snapshot mute reason', 'is_active' => true]);
        $category = Category::create(['name' => 'Fixture', 'slug' => 'fixture', 'is_active' => true]);
        $source = Source::create(['name' => 'Fixture', 'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss', 'category_id' => $category->id, 'is_active' => true]);
        $article = Article::create(['title' => 'Fixture', 'slug' => 'comment-snapshot', 'original_url' => 'https://example.test/snapshot',
            'category_id' => $category->id, 'source_id' => $source->id, 'published_at' => now()]);
        // Gate 9b: sanctions live on MongoDB; observe `find` commands on that collection for this user.
        $connection = DB::connection('mongodb');
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->enableQueryLog();
        $reads = 0;
        $connection->listen(function ($query) use (&$reads, $mute, $user) {
            if (preg_match('/^\{\s*"find"\s*:\s*"sanctions"/', $query->sql) && str_contains($query->sql, (string) $user->id)) {
                $reads++;
                // Lift immediately after the find executes, before Blade uses its result.
                DB::connection('mongodb')->table('sanctions')->where('_id', $mute->id)->update(['is_active' => false]);
            }
        });
        try {
            $this->actingAs($user)->get('/articles/'.$article->slug)->assertOk()->assertSee('Snapshot mute reason');
            $this->assertSame(1, $reads);
            $this->assertNull($user->blockingCommentSanction());
            $reads = 0;
            $this->get('/articles/'.$article->slug)->assertOk()->assertSee('Đăng bình luận')->assertDontSee('Snapshot mute reason');
            $this->assertSame(1, $reads);
        } finally {
            $connection->disableQueryLog();
            $connection->setEventDispatcher($dispatcher);
        }
    }

    public function test_explicit_null_epoch_supports_password_ban_and_account_revoke(): void
    {
        foreach (['password', 'ban', 'revoke'] as $action) {
            $user = User::factory()->create();
            User::where('_id', $user->id)->update(['api_token_epoch' => null]);
            $this->assertNull($user->fresh()->api_token_epoch);
            $first = $this->login($user);
            $next = $this->refreshToken($first['refresh_token'])->assertOk()->json();
            if ($action === 'password') {
                $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])
                    ->assertSessionHasNoErrors()->assertRedirect();
                $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
            } elseif ($action === 'ban') {
                $admin = User::factory()->create(['role' => 'admin']);
                $this->actingAs($admin)->post('/admin/sanctions', ['user_id' => $user->id, 'type' => 'permanent_ban', 'reason' => 'Fixture'])
                    ->assertRedirect(route('admin.sanctions.index'))->assertSessionMissing('error')->assertSessionHas('success');
                $sanction = $user->sanctions()->sole();
                $sanction->update(['is_active' => false]);
            } else {
                app(ApiTokenService::class)->revokeAllForUser((string) $user->id);
            }
            $this->assertSame($action === 'ban' ? 3 : 1, $user->fresh()->api_token_epoch);
            $this->me($first['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->me($next['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
            $this->refreshToken($next['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'token_family_revoked');
        }
    }
}
