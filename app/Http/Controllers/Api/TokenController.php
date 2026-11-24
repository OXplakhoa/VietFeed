<?php

namespace App\Http\Controllers\Api;

use App\ApiTokens\ApiTokenService;
use App\ApiTokens\TokenException;
use App\Http\Controllers\BoostController;
use App\Models\User;
use App\StoryLens\StoryLens;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

final class TokenController
{
    public function login(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:255', 'password' => 'required|string|max:1024']);
        $limiter = new RateLimiter(Cache::store('redis'));
        $ipKey = 'api-login:ip:'.hash('sha256', (string) $request->ip());
        $key = 'api-login:account:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if ($limiter->tooManyAttempts($key, 5) || $limiter->tooManyAttempts($ipKey, 30)) {
            return response()->json(['error' => ['code' => 'rate_limited']], 429)
                ->header('Retry-After', max($limiter->availableIn($key), $limiter->availableIn($ipKey), 1))
                ->header('Cache-Control', 'no-store');
        }
        $user = User::where('email', $data['email'])->first();
        // Equal hash work on either branch, including a Google-only user with no password.
        $dummy = Hash::make(bin2hex(random_bytes(32)));
        $valid = Hash::check($data['password'], $user?->password ?: $dummy);
        if (! $valid || ! $user || $user->isBanned() || $user->isPendingDeletion()) {
            $limiter->hit($key, 60);
            $limiter->hit($ipKey, 60);
            throw new TokenException('invalid_credentials');
        }

        $limiter->clear($key);

        return response()->json($tokens->issue($user))->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $data = $request->validate(['refresh_token' => 'required|string|max:1024']);

        return response()->json($tokens->refresh($data['refresh_token']))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $tokens->revokeFamily($request->attributes->get('api_token_family'));

        return response()->json(['status' => 'logged_out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'email_verified' => $user->hasVerifiedEmail()]]);
    }

    public function story(string $story, StoryLens $lens): JsonResponse
    {
        return response()->json($lens->readForPage($story));
    }

    public function boost(Request $request, BoostController $boost): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            throw new TokenException('email_unverified', 403);
        }
        if (! $request->user()->canBoost()) {
            throw new TokenException('interaction_forbidden', 403);
        }

        return $boost->toggle($request);
    }
}
