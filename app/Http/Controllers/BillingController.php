<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function pricing(Request $request): View
    {
        return view('billing.pricing', [
            'priceIdConfigured' => filled(config('services.stripe.pro_price_id')),
            'isPro' => $request->user()?->isPro() ?? false,
        ]);
    }

    public function checkout(Request $request)
    {
        $priceId = config('services.stripe.pro_price_id');

        if (! $priceId) {
            return redirect()->route('pricing')
                ->with('error', 'Chưa cấu hình gói Stripe Pro. Kiểm tra STRIPE_PRO_PRICE_ID trong .env.');
        }

        if ($request->user()->isPro()) {
            return redirect()->route('profile.reading-pass')
                ->with('success', 'Tài khoản của bạn đang dùng VietFeed Pro.');
        }

        return $request->user()
            ->newSubscription('pro', $priceId)
            ->checkout([
                'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('pricing'),
                'metadata' => [
                    'product' => 'vietfeed_pro',
                    'user_id' => (string) $request->user()->id,
                ],
            ]);
    }

    public function portal(Request $request)
    {
        if (! $request->user()->stripe_id) {
            return redirect()->route('pricing')
                ->with('error', 'Bạn chưa có hồ sơ thanh toán Stripe.');
        }

        return $request->user()->redirectToBillingPortal(route('profile.reading-pass'));
    }

    public function success(): RedirectResponse
    {
        return redirect()->route('profile.reading-pass')
            ->with('success', 'Stripe đã ghi nhận thanh toán. Pro sẽ hiển thị sau khi webhook sandbox cập nhật đăng ký.');
    }
}
