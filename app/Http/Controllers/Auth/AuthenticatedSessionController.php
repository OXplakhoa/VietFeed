<?php

namespace App\Http\Controllers\Auth;

use App\Accounts\AccountLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ReadingPassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, ReadingPassService $readingPass, AccountLifecycle $lifecycle): RedirectResponse
    {
        $guestSessionId = $request->session()->getId();

        $request->authenticate();

        $request->session()->regenerate();
        if ($request->user()->isPendingDeletion()) {
            // Signing in never restores an account: it only opens the recovery page.
            $request->session()->put(AccountLifecycle::SESSION_KEY, $lifecycle->recoveryStamp($request->user()));

            return redirect()->route('account.recover');
        }
        $readingPass->transferGuestUnlocks($request->user(), $guestSessionId);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
