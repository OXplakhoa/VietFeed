<?php

namespace App\Http\Controllers;

use App\Billing\BillingGateway;
use App\Billing\BillingUnavailable;
use App\Billing\InvalidWebhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

        try {
            return redirect()->away(app(BillingGateway::class)->checkout($request->user()), 303);
        } catch (BillingUnavailable) {
            return redirect()->route('pricing')->with('error', 'Stripe hiện không khả dụng. Vui lòng thử lại sau.');
        }
    }

    public function portal(Request $request)
    {
        if (! $request->user()->stripe_customer_id) {
            return redirect()->route('pricing')
                ->with('error', 'Bạn chưa có hồ sơ thanh toán Stripe.');
        }

        try {
            return redirect()->away(app(BillingGateway::class)->portal($request->user()), 303);
        } catch (BillingUnavailable) {
            return redirect()->route('pricing')->with('error', 'Stripe hiện không khả dụng. Vui lòng thử lại sau.');
        }
    }

    public function webhook(Request $request)
    {
        try {
            app(BillingGateway::class)->receive($request->getContent(), $request->header('Stripe-Signature'));

            return new Response('OK', 200);
        } catch (InvalidWebhook) {
            return new Response('Invalid webhook', 400);
        } catch (\Throwable $e) {
            Log::warning('Billing webhook unavailable', ['exception' => $e::class]);

            return new Response('Webhook temporarily unavailable', 503);
        }
    }

    public function success(): RedirectResponse
    {
        return redirect()->route('profile.reading-pass')
            ->with('success', 'Stripe đã ghi nhận thanh toán. Pro sẽ hiển thị sau khi webhook sandbox cập nhật đăng ký.');
    }
}
