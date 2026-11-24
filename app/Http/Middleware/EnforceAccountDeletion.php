<?php

namespace App\Http\Middleware;

use App\Accounts\AccountLifecycle;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 9.7b: an account with a deletion request is closed on every web route. Sessions created before the request die
 * on their next request; only the session that signed in after the request (marked at login) may reach the recovery page.
 */
class EnforceAccountDeletion
{
    public function __construct(private AccountLifecycle $lifecycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isPendingDeletion()) {
            return $next($request);
        }

        if ($request->hasSession() && $request->session()->get(AccountLifecycle::SESSION_KEY) === $this->lifecycle->recoveryStamp($user)) {
            if ($request->routeIs('account.recover', 'account.recover.store', 'logout')) {
                return $next($request);
            }

            return redirect()->route('account.recover');
        }

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login');
    }
}
