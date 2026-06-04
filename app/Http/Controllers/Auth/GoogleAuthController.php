<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        /** @var GoogleProvider $provider */
        $provider = Socialite::driver('google');

        return $provider
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(ReadingPassService $readingPass): RedirectResponse
    {
        $guestSessionId = request()->session()->getId();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Không thể đăng nhập với Google. Vui lòng thử lại.');
        }

        if (! $googleUser->getEmail()) {
            return redirect()->route('login')->with('error', 'Tài khoản Google này không cung cấp email hợp lệ.');
        }

        $wasRecentlyCreated = false;

        $user = DB::transaction(function () use ($googleUser, &$wasRecentlyCreated) {
            $user = User::query()
                ->where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $wasRecentlyCreated = true;

                return User::create([
                    'name' => $googleUser->getName() ?: Str::before($googleUser->getEmail(), '@'),
                    'email' => $googleUser->getEmail(),
                    'password' => Str::random(40),
                    'role' => 'user',
                    'avatar' => $googleUser->getAvatar(),
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                ]);
            }

            $updates = [];

            if (! $user->google_id) {
                $updates['google_id'] = $googleUser->getId();
            }

            if (! $user->email_verified_at) {
                $updates['email_verified_at'] = now();
            }

            if ($googleUser->getAvatar()) {
                $updates['avatar'] = $googleUser->getAvatar();
            }

            if (empty($user->name) && $googleUser->getName()) {
                $updates['name'] = $googleUser->getName();
            }

            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }

            return $user->fresh();
        });

        Auth::login($user, remember: true);
        request()->session()->regenerate();
        $readingPass->transferGuestUnlocks($user, $guestSessionId);

        if ($wasRecentlyCreated) {
            return redirect()->route('onboarding.interests')->with('success', 'Chào mừng bạn đến với VietFeed. Hãy chọn chủ đề yêu thích.');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
