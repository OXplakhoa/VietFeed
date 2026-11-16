<?php

namespace App\ApiTokens;

use App\Interaction\RedisResult;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SensitiveParameter;
use Throwable;

class ApiTokenService
{
    public const EXPIRY_GRACE_SECONDS = 60;

    public function issue(User $user): array
    {
        // Preserve the epoch from the same Mongo snapshot as the credential check.
        $access = $this->random();
        $refresh = $this->random();
        $accessTtl = $this->ttl('access');
        $refreshTtl = $this->ttl('refresh');
        $this->execute('issue', [(string) $user->id, $this->random(), hash('sha256', $access), hash('sha256', $refresh), $accessTtl, $refreshTtl, (int) $user->api_token_epoch]);

        return $this->pair($access, $refresh, $accessTtl, $refreshTtl);
    }

    /** Every call loads identity and sanctions from authoritative stores. */
    public function authenticate(#[SensitiveParameter] string $token): array
    {
        $result = $this->execute('access', [hash('sha256', $token)]);

        return ['user' => $this->familyUser($result), 'family' => $result[2]];
    }

    public function refresh(#[SensitiveParameter] string $token): array
    {
        $hash = hash('sha256', $token);
        $identity = $this->execute('refresh_identity', [$hash]);
        $this->familyUser($identity);
        $access = $this->random();
        $refresh = $this->random();
        $accessTtl = $this->ttl('access');
        $refreshTtl = $this->ttl('refresh');
        $this->execute('rotate', [$hash, hash('sha256', $access), hash('sha256', $refresh), $accessTtl, $refreshTtl]);

        return $this->pair($access, $refresh, $accessTtl, $refreshTtl);
    }

    public function revokeFamily(string $family): void
    {
        $this->execute('revoke_family', [$family]);
    }

    public function revokeAllForUser(string $userId): void
    {
        User::normalizeApiTokenEpoch($userId);
        User::where('_id', $userId)->increment('api_token_epoch');
        $this->cleanupForUser($userId);
    }

    public function cleanupForUser(string $userId): void
    {
        try {
            $this->execute('revoke_user', [$userId]);
        } catch (Throwable $e) {
            Log::warning('API token cleanup failed', ['operation' => 'revoke_user', 'exception_type' => $e::class]);
        }
    }

    private function familyUser(array $identity): User
    {
        $user = User::find($identity[1]);
        if (! $user) {
            throw new TokenException('account_unavailable');
        }
        if ((int) $user->api_token_epoch !== (int) $identity[3]) {
            throw new TokenException('token_family_revoked');
        }
        if ($user->isBanned()) {
            throw new TokenException('account_unavailable');
        }

        return $user;
    }

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function ttl(string $kind): int
    {
        $value = config('nosql.api_tokens.'.$kind.'_ttl_seconds');
        if ((! is_int($value) && ! (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 31536000) {
            throw new \LogicException('Invalid API token TTL configuration');
        }

        return (int) $value;
    }

    private function pair(#[SensitiveParameter] string $access, #[SensitiveParameter] string $refresh, int $accessTtl, int $refreshTtl): array
    {
        return ['token_type' => 'Bearer', 'access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $accessTtl, 'refresh_expires_in' => $refreshTtl];
    }

    private function execute(string $operation, array $arguments): array
    {
        try {
            if (app()->runningUnitTests() && Redis::connection('nosql')->client()->getDbNum() !== 15) {
                throw new \RuntimeException('Refusing API token test outside Redis DB15');
            }
            $result = RedisResult::run('API token operation',
                fn () => Redis::connection('nosql')->eval(file_get_contents(__DIR__.'/tokens.lua'), 1, 'api-token:', $operation, self::EXPIRY_GRACE_SECONDS, ...$arguments),
                fn ($value) => is_array($value) && isset($value[0]) && is_string($value[0]));
        } catch (Throwable $e) {
            Log::warning('API token store failed', ['operation' => $operation, 'exception_type' => $e::class]);
            // Do not propagate Redis keys, hashes or driver diagnostics to logs/HTTP.
            throw new TokenException('service_unavailable', 503);
        }
        if ($result[0] !== 'ok') {
            throw new TokenException($result[0]);
        }

        return $result;
    }
}
