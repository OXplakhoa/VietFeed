<?php

namespace App\Http\Controllers\Auth;

use App\Accounts\AccountLifecycle;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use MongoDB\Driver\Exception\BulkWriteException as MongoBulkWriteException;
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

    public function callback(ReadingPassService $readingPass, AccountLifecycle $lifecycle): RedirectResponse
    {
        $guestSessionId = request()->session()->getId();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Không thể đăng nhập với Google. Vui lòng thử lại.');
        }

        $email = $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();
        if (! $email || $googleId === '') {
            return redirect()->route('login')->with('error', 'Tài khoản Google này không cung cấp email hợp lệ.');
        }

        $verified = ($googleUser->getRaw()['email_verified'] ?? false) === true;
        $user = User::where('google_id', $googleId)->first()
            ?? ($verified ? User::where('email', $email)->first() : null);

        if ($user && $user->google_id !== $googleId && ($user->google_id !== null
            || ($user->getAttribute('password_reset_requires_verified_email') === true && ! $user->hasVerifiedEmail()))) {
            return $this->emailSignInRefused();
        }

        if ($user?->isPendingDeletion()) {
            // A pending account is not touched (no linking, no verification, no guest transfer): sign in only opens recovery.
            Auth::login($user, remember: false);
            request()->session()->regenerate();
            request()->session()->put(AccountLifecycle::SESSION_KEY, $lifecycle->recoveryStamp($user));

            return redirect()->route('account.recover');
        }

        $wasRecentlyCreated = $user === null;
        if ($wasRecentlyCreated && ! $verified) {
            return redirect()->route('login')->with('error', 'Vui lòng xác minh email của tài khoản Google trước khi đăng nhập.');
        }

        try {
            if ($wasRecentlyCreated) {
                $user = User::create([
                    'name' => $googleUser->getName() ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(40),
                    'role' => 'user',
                    'avatar' => $googleUser->getAvatar(),
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ]);
            } else {
                $updates = [];
                if ($googleUser->getAvatar()) {
                    $updates['avatar'] = $googleUser->getAvatar();
                }
                if (empty($user->name) && $googleUser->getName()) {
                    $updates['name'] = $googleUser->getName();
                }

                if ($user->google_id === null) {
                    // An email change bumps the epoch. A concurrent change or link must win over this lookup.
                    if (User::whereKey($user->id)->where('email', $email)->whereNull('google_id')->whereNull('deletion')
                        ->where('api_token_epoch', $user->getRawOriginal('api_token_epoch'))
                        ->update($updates + ['google_id' => $googleId]) !== 1) {
                        return $this->emailSignInRefused();
                    }
                } elseif ($updates !== []) {
                    $user->forceFill($updates)->save();
                }

                if ($verified && $user->email === $email && ! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                $user = $user->fresh();
            }
        } catch (MongoBulkWriteException $e) {
            if ($e->getCode() !== 11000) {
                throw $e;
            }

            return $this->emailSignInRefused();
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();
        $readingPass->transferGuestUnlocks($user, $guestSessionId);

        if ($wasRecentlyCreated) {
            return redirect()->route('onboarding.interests')->with('success', 'Chào mừng bạn đến với VietFeed. Hãy chọn chủ đề yêu thích.');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function emailSignInRefused(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'Không thể đăng nhập bằng địa chỉ Google này. Hãy đăng nhập bằng mật khẩu và xác minh email của bạn.',
        ]);
    }
}
