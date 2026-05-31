<x-guest-layout
    title="Đăng nhập"
    eyebrow="Bản tin dành cho người đọc có gu"
    headline="Đọc tin Việt theo gu của bạn."
    manifesto="Theo dõi những câu chuyện quan trọng nhất trong ngày bằng một không gian đọc tin mang tinh thần tòa soạn hiện đại."
>
    <div class="vf-auth-form-header">
        <h2 class="auth-title">Chào mừng trở lại</h2>
        <p class="auth-subtitle">Đăng nhập để lưu bài, theo dõi chủ đề yêu thích và tiếp tục nhịp đọc của bạn.</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <a href="{{ route('auth.google.redirect') }}" class="vf-google-btn mb-4">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.24 1.25-.96 2.31-2.04 3.02l3.3 2.56c1.92-1.77 3.04-4.38 3.04-7.49 0-.71-.06-1.39-.18-2.04H12Z"/>
            <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.43l-3.3-2.56c-.91.61-2.08.97-3.32.97-2.55 0-4.71-1.72-5.48-4.03l-3.41 2.64A9.99 9.99 0 0 0 12 22Z"/>
            <path fill="#4A90E2" d="M6.52 13.95A5.99 5.99 0 0 1 6.2 12c0-.68.12-1.34.32-1.95L3.11 7.4A9.99 9.99 0 0 0 2 12c0 1.61.38 3.14 1.11 4.6l3.41-2.65Z"/>
            <path fill="#FBBC05" d="M12 6.02c1.47 0 2.8.5 3.84 1.48l2.88-2.88C16.95 2.98 14.7 2 12 2 8.09 2 4.73 4.24 3.11 7.4l3.41 2.65c.77-2.32 2.93-4.03 5.48-4.03Z"/>
        </svg>
        <span>Tiếp tục với Google</span>
    </a>

    <div class="vf-auth-divider"><span>hoặc đăng nhập bằng email</span></div>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="ban@toasoan.vn" required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <label for="password" class="form-label mb-0">Mật khẩu</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="auth-link">Quên mật khẩu?</a>
                @endif
            </div>
            <div class="pw-wrap mt-1">
                <input id="password" type="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="••••••••" required autocomplete="current-password">
                <button type="button" class="pw-toggle" tabindex="-1" aria-label="Hiện/ẩn mật khẩu">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div class="form-check mb-0">
                <input id="remember_me" type="checkbox" class="form-check-input" name="remember">
                <label for="remember_me" class="form-check-label vf-auth-check-label">
                    Ghi nhớ đăng nhập
                </label>
            </div>
            <span class="vf-auth-meta">Bảo mật bằng phiên đăng nhập tiêu chuẩn của VietFeed.</span>
        </div>

        <button type="submit" class="btn btn-accent w-100 justify-content-center">Đăng nhập</button>

        <p class="text-center mb-0 mt-4 vf-auth-footnote">
            Chưa có tài khoản?
            <a href="{{ route('register') }}" class="auth-link ms-1">Tạo tài khoản</a>
        </p>
    </form>
</x-guest-layout>
