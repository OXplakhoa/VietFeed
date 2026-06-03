<x-app-layout>
    <x-slot name="title">Gói đọc — VietFeed</x-slot>

    <div class="container py-4" style="max-width:860px">
        <div class="section-header mb-4">
            <h2><i class="bi bi-ticket-perforated me-2" style="color:var(--accent)"></i>Gói đọc VietFeed</h2>
        </div>

        <div class="p-4 mb-4 position-relative overflow-hidden" style="background:linear-gradient(135deg, rgba(230,57,70,.12), var(--surface));border:1px solid rgba(230,57,70,.25);border-radius:18px">
            <div style="position:absolute;right:-30px;bottom:-70px;font-family:'Playfair Display',serif;font-size:9rem;color:rgba(230,57,70,.08);line-height:1">PASS</div>
            <div style="font-size:.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em">Trạng thái hiện tại</div>
            <h1 class="serif mt-2 mb-2" style="color:var(--text);font-size:clamp(1.8rem,4vw,3rem)">
                @if($allowance['limit'] === -1)
                    Không giới hạn lượt mở bài
                @else
                    Còn {{ $allowance['remaining'] }} lượt mở bài
                @endif
            </h1>
            <p class="mb-3" style="color:var(--text-muted)">{{ ucfirst($allowance['window']) }} · {{ $allowance['reset_label'] }}</p>

            @if($allowance['limit'] !== -1)
            <div style="height:10px;background:var(--surface-alt);border-radius:999px;overflow:hidden;max-width:520px">
                <div style="height:100%;width:{{ min(100, ($allowance['used'] / max(1, $allowance['limit'])) * 100) }}%;background:var(--accent);border-radius:999px"></div>
            </div>
            @endif
        </div>

        <div class="row g-3 mb-4">
            @foreach([
                ['name' => 'Khách', 'quota' => '5 lượt/ngày', 'note' => 'Theo phiên trình duyệt, đặt lại lúc nửa đêm Việt Nam.'],
                ['name' => 'Tài khoản', 'quota' => '8 lượt/ngày', 'note' => 'Đăng ký tài khoản để giữ lượt mở theo người dùng.'],
                ['name' => 'Đã xác minh', 'quota' => '15 lượt/5 giờ', 'note' => 'Cửa sổ cuộn 5 giờ cho người dùng đã xác minh email.'],
                ['name' => 'Pro', 'quota' => 'Không giới hạn', 'note' => 'Nâng cấp qua Stripe sandbox; quản lý/hủy trong Billing Portal.'],
            ] as $tier)
            <div class="col-md-6">
                <div class="h-100 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:14px">
                    <div class="d-flex justify-content-between gap-3">
                        <strong style="color:var(--text)">{{ $tier['name'] }}</strong>
                        <span style="color:var(--accent);font-size:.86rem;font-weight:700">{{ $tier['quota'] }}</span>
                    </div>
                    <p class="mb-0 mt-2" style="color:var(--text-muted);font-size:.86rem">{{ $tier['note'] }}</p>
                </div>
            </div>
            @endforeach
        </div>

        <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:14px">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="serif mb-1" style="color:var(--text)">VietFeed Pro</h5>
                    <p class="mb-0" style="color:var(--text-muted);font-size:.9rem">Mở bài không giới hạn qua Stripe sandbox.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if(auth()->user()->isPro())
                    <a href="{{ route('billing.portal') }}" class="btn-accent" style="text-decoration:none">
                        <i class="bi bi-credit-card me-1"></i>Quản lý thanh toán
                    </a>
                    @elseif(auth()->user()->hasVerifiedEmail())
                    <form method="POST" action="{{ route('billing.checkout') }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn-accent">Nâng cấp Pro</button>
                    </form>
                    @else
                    <a href="{{ route('verification.notice') }}" class="btn-accent" style="text-decoration:none">Xác minh email để nâng cấp</a>
                    @endif
                    <a href="{{ route('pricing') }}" class="btn-outline-accent" style="text-decoration:none">Xem bảng giá</a>
                </div>
            </div>
        </div>

        <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:14px">
            <h5 class="serif mb-2" style="color:var(--text)">Vì sao có Gói đọc?</h5>
            <p class="mb-0" style="color:var(--text-muted);font-size:.92rem;line-height:1.7">
                VietFeed tổng hợp và sắp xếp tin từ các nguồn uy tín. Gói đọc giới hạn trải nghiệm đọc tiện lợi trong VietFeed; khi hết lượt, hãy tạo tài khoản, xác minh email hoặc nâng cấp Pro để tiếp tục.
            </p>
        </div>
    </div>
</x-app-layout>
