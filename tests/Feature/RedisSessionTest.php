<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Gate 5.1 spike (GitHub #13): sessions on live Redis (SESSION_DRIVER=redis).
// Live-redis exception inside the suite (everything else uses array fakes per
// phpunit.xml): requires compose redis healthy (`./scripts/nosql-health.sh`).
// Isolation: sessions ride the `default` connection on DB 15 (dev uses DB 0/1),
// flushed in tearDown — never touches dev data. Run with:
//   vendor/bin/phpunit tests/Feature/RedisSessionTest.php
//
// Test-client caveat: Laravel's test client sends NO response cookies back
// automatically, so every request here re-syncs the jar (syncCookies) to behave
// like a browser; without that, logout would destroy a fresh empty session and
// leave the real one orphaned.
class RedisSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'redis',
            'session.connection' => 'default',
            'database.redis.default.database' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();

        parent::tearDown();
    }

    private function syncCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    private function sessionKeys(string $id): array
    {
        return Redis::connection('default')->keys("*{$id}*");
    }

    private function login(array $credentials): array
    {
        $this->syncCookies($this->get('/login'));
        $guestId = $this->app['session']->getId();

        $response = $this->post('/login', $credentials);
        $this->syncCookies($response);

        return [$guestId, $this->app['session']->getId(), $response];
    }

    public function test_login_writes_session_to_redis_and_regenerates_id(): void
    {
        $user = User::factory()->create();
        [$guestId, $authId, $response] = $this->login([
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertNotSame($guestId, $authId);
        $this->assertNotEmpty($this->sessionKeys($authId));
        $this->assertEmpty($this->sessionKeys($guestId));
    }

    public function test_logout_destroys_redis_session(): void
    {
        $user = User::factory()->create();
        [, $authId] = $this->login([
            'email' => $user->email,
            'password' => 'password',
        ]);
        $this->assertNotEmpty($this->sessionKeys($authId));

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
        $this->assertEmpty($this->sessionKeys($authId));
    }

    public function test_remember_me_survives_session_flush(): void
    {
        $user = User::factory()->create();
        [, , $response] = $this->login([
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertCookie(Auth::getRecallerName());
        $this->assertNotNull($user->fresh()->remember_token);

        // Cutover/flush simulation: session gone, recaller cookie remains.
        Redis::connection('default')->flushdb();

        $response = $this->get('/dashboard');

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_concurrent_devices_keep_distinct_sessions(): void
    {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'password'];

        [, $deviceA] = $this->login($credentials);

        // Second device: garbage session cookie forces a clean fresh session.
        $this->withUnencryptedCookie((string) config('session.cookie'), 'device-b');
        [, $deviceB] = $this->login($credentials);

        $this->assertNotSame($deviceA, $deviceB);
        $this->assertNotEmpty($this->sessionKeys($deviceA));
        $this->assertNotEmpty($this->sessionKeys($deviceB));

        $this->post('/logout');

        $this->assertEmpty($this->sessionKeys($deviceB));
        $this->assertNotEmpty($this->sessionKeys($deviceA));
    }
}
