<x-guest-layout
    title="Đăng ký"
    eyebrow="Thiết lập nhịp đọc riêng"
    headline="Một tài khoản, một newsroom rất riêng."
    manifesto="Lưu lại những chủ đề bạn quan tâm, chọn gu đọc yêu thích và bắt đầu một nguồn tin gọn gàng hơn mỗi ngày."
>
    <div class="vf-auth-form-header">
        <h2 class="auth-title">Tạo tài khoản</h2>
        <p class="auth-subtitle">Tham gia VietFeed để cá nhân hóa bản tin, lưu bài và theo dõi chủ đề bạn thực sự quan tâm.</p>
    </div>

    <a href="{{ route('auth.google.redirect') }}" class="vf-google-btn mb-4">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.24 1.25-.96 2.31-2.04 3.02l3.3 2.56c1.92-1.77 3.04-4.38 3.04-7.49 0-.71-.06-1.39-.18-2.04H12Z"/>
            <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.43l-3.3-2.56c-.91.61-2.08.97-3.32.97-2.55 0-4.71-1.72-5.48-4.03l-3.41 2.64A9.99 9.99 0 0 0 12 22Z"/>
            <path fill="#4A90E2" d="M6.52 13.95A5.99 5.99 0 0 1 6.2 12c0-.68.12-1.34.32-1.95L3.11 7.4A9.99 9.99 0 0 0 2 12c0 1.61.38 3.14 1.11 4.6l3.41-2.65Z"/>
            <path fill="#FBBC05" d="M12 6.02c1.47 0 2.8.5 3.84 1.48l2.88-2.88C16.95 2.98 14.7 2 12 2 8.09 2 4.73 4.24 3.11 7.4l3.41 2.65c.77-2.32 2.93-4.03 5.48-4.03Z"/>
        </svg>
        <span>Đăng ký nhanh với Google</span>
    </a>

    <div class="vf-auth-divider"><span>hoặc tạo tài khoản bằng email</span></div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">Họ và tên</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   placeholder="Nguyễn Văn A" required autofocus autocomplete="name">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="ban@toasoan.vn" required autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Mật khẩu</label>
            <div class="pw-wrap">
                <input id="password" type="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="Tối thiểu 8 ký tự" required autocomplete="new-password">
                <button type="button" class="pw-toggle" tabindex="-1" aria-label="Hiện/ẩn mật khẩu">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Xác nhận mật khẩu</label>
            <div class="pw-wrap">
                <input id="password_confirmation" type="password" name="password_confirmation"
                       class="form-control" placeholder="Nhập lại mật khẩu"
                       required autocomplete="new-password">
                <button type="button" class="pw-toggle" tabindex="-1" aria-label="Hiện/ẩn mật khẩu">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-accent w-100 justify-content-center">Tạo tài khoản</button>

        <p class="text-center mb-0 mt-4 vf-auth-footnote">
            Đã có tài khoản?
            <a href="{{ route('login') }}" class="auth-link ms-1">Đăng nhập</a>
        </p>
    </form>
</x-guest-layout>
