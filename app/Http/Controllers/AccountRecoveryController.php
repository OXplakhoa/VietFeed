<?php

namespace App\Http\Controllers;

use App\Accounts\AccountLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountRecoveryController extends Controller
{
    public function show(Request $request, AccountLifecycle $lifecycle): View|RedirectResponse
    {
        $state = $lifecycle->stateOf($request->user());
        if ($state === null) {
            return redirect()->route('home');
        }

        return view('account.recover', ['state' => $state]);
    }

    public function store(Request $request, AccountLifecycle $lifecycle): RedirectResponse
    {
        $lifecycle->recover((string) $request->user()->id);
        $request->session()->forget(AccountLifecycle::SESSION_KEY);

        return redirect()->route('home')->with('success', 'Đã khôi phục tài khoản. Nếu bạn từng dùng VietFeed Pro, hãy đăng ký lại.');
    }
}
