<x-app-layout>
    <x-slot name="title">VietFeed Pro — Gói đọc không giới hạn</x-slot>

    @push('styles')
    <style>
        .pricing-press {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(230,57,70,.28);
            border-radius: 26px;
            background:
                radial-gradient(circle at 12% 8%, rgba(230,57,70,.22), transparent 28%),
                linear-gradient(135deg, color-mix(in srgb, var(--surface) 92%, #e63946), var(--surface));
        }
        .pricing-press::after {
            content: "PRO";
            position: absolute;
            right: -1.2rem;
            bottom: -2.4rem;
            font-family: 'Playfair Display', serif;
            font-size: clamp(7rem, 18vw, 15rem);
            line-height: 1;
            color: rgba(230,57,70,.07);
            pointer-events: none;
        }
        .pricing-proof {
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 88%, transparent);
            border-radius: 16px;
        }
        .pricing-plan {
            position: relative;
            border: 1px solid rgba(230,57,70,.34);
            border-radius: 22px;
            background: var(--surface);
            box-shadow: 0 28px 80px rgba(0,0,0,.18);
        }
        .pricing-plan__stamp {
            display: inline-flex;
            gap: .35rem;
            align-items: center;
            padding: .35rem .7rem;
            border: 1px solid rgba(230,57,70,.3);
            border-radius: 999px;
            color: var(--accent);
            background: rgba(230,57,70,.1);
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
    </style>
    @endpush

    <div class="container py-5" style="max-width:1080px">
        <div class="pricing-press p-4 p-lg-5 mb-4">
            <div class="row align-items-center g-4 position-relative" style="z-index:1">
                <div class="col-lg-7">
                    <div class="pricing-plan__stamp mb-3"><i class="bi bi-stars"></i> Stripe sandbox</div>
                    <h1 class="serif mb-3" style="font-size:clamp(2.4rem,7vw,5.5rem);line-height:.95;color:var(--text)">
                        VietFeed Pro
                    </h1>
                    <p class="mb-4" style="color:var(--text-secondary);font-size:1.02rem;max-width:620px">
                        Mở bài không giới hạn trong VietFeed: không chờ reset, không mất mạch đọc, vẫn giữ tinh thần báo chí gọn gàng.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        @auth
                            @if($isPro)
                            <a href="{{ route('billing.portal') }}" class="btn-accent" style="text-decoration:none">
                                <i class="bi bi-credit-card me-1"></i>Quản lý thanh toán
                            </a>
                            @elseif(auth()->user()->hasVerifiedEmail())
                            <form method="POST" action="{{ route('billing.checkout') }}" class="m-0">
                                @csrf
                                <button type="submit" class="btn-accent" @disabled(! $priceIdConfigured)>
                                    <i class="bi bi-lock-fill me-1"></i>Nâng cấp qua Stripe
                                </button>
                            </form>
                            @else
                            <a href="{{ route('verification.notice') }}" class="btn-accent" style="text-decoration:none">
                                Xác minh email để nâng cấp
                            </a>
                            @endif
                        @else
                        <a href="{{ route('register') }}" class="btn-accent" style="text-decoration:none">Tạo tài khoản để nâng cấp</a>
                        <a href="{{ route('login') }}" class="btn-outline-accent" style="text-decoration:none">Đăng nhập</a>
                        @endauth
                    </div>
                    @unless($priceIdConfigured)
                    <div class="mt-3" style="color:#f59e0b;font-size:.86rem">
                        <i class="bi bi-exclamation-triangle me-1"></i>Thiếu STRIPE_PRO_PRICE_ID trong .env nên checkout đang tắt.
                    </div>
                    @endunless
                </div>

                <div class="col-lg-5">
                    <div class="pricing-plan p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div style="color:var(--text-muted);font-size:.78rem;text-transform:uppercase;letter-spacing:.1em">Gói tháng</div>
                                <h2 class="serif mb-0" style="color:var(--text)">Pro</h2>
                            </div>
                            <span style="color:var(--accent);font-weight:800">Sandbox</span>
                        </div>
                        <div class="mb-3">
                            <span class="serif" style="font-size:2.8rem;color:var(--text);line-height:1">49.000₫</span>
                            <span style="color:var(--text-muted)">/ tháng</span>
                        </div>
                        <ul class="list-unstyled m-0" style="color:var(--text-secondary);font-size:.92rem">
                            <li class="mb-2"><i class="bi bi-check2-circle me-2" style="color:#22c55e"></i>Không giới hạn lượt mở bài</li>
                            <li class="mb-2"><i class="bi bi-check2-circle me-2" style="color:#22c55e"></i>Pro Badge trên hồ sơ và bình luận</li>
                            <li class="mb-2"><i class="bi bi-check2-circle me-2" style="color:#22c55e"></i>Quản lý/hủy qua Stripe Billing Portal</li>
                            <li><i class="bi bi-check2-circle me-2" style="color:#22c55e"></i>Và thêm nhiều tính năng khác...</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            @foreach([
                ['icon' => 'bi-ticket-perforated', 'title' => 'Không đếm lượt', 'body' => 'Pro bỏ qua quota Reading Pass và không tiêu hao lượt khi mở bài.'],
                ['icon' => 'bi-shield-check', 'title' => 'Thanh toán an toàn qua Stripe', 'body' => 'Trạng thái Pro được cập nhật tự động sau thanh toán; bạn có thể quản lý hoặc hủy gói trong cổng Stripe.'],
                ['icon' => 'bi-newspaper', 'title' => 'Đúng chất VietFeed', 'body' => 'Giới hạn áp dụng cho trải nghiệm đọc tổng hợp trong VietFeed, không khóa web gốc của báo.'],
            ] as $item)
            <div class="col-md-4">
                <div class="pricing-proof h-100 p-3">
                    <i class="bi {{ $item['icon'] }}" style="color:var(--accent);font-size:1.35rem"></i>
                    <h3 class="serif mt-2 mb-1" style="font-size:1.15rem;color:var(--text)">{{ $item['title'] }}</h3>
                    <p class="mb-0" style="font-size:.88rem;color:var(--text-muted)">{{ $item['body'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
