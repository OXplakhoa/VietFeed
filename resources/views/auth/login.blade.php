<x-guest-layout>
    @php $title = 'Đăng nhập' @endphp

    <h1 class="auth-title">Chào mừng trở lại</h1>
    <p class="auth-subtitle mb-4">Đăng nhập để tiếp tục đọc tin tức</p>

    @if (session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@example.com" required autofocus autocomplete="username">
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
            <input id="password" type="password" name="password"
                   class="form-control mt-1 @error('password') is-invalid @enderror"
                   placeholder="••••••••" required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <div class="form-check">
                <input id="remember_me" type="checkbox" class="form-check-input" name="remember">
                <label for="remember_me" class="form-check-label" style="color: var(--text-muted); font-size: 0.875rem;">
                    Ghi nhớ đăng nhập
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-accent w-100">Đăng nhập</button>

        <hr class="auth-divider my-4">

        <p class="text-center mb-0" style="color: var(--text-muted); font-size: 0.875rem;">
            Chưa có tài khoản?
            <a href="{{ route('register') }}" class="auth-link ms-1">Đăng ký ngay</a>
        </p>
    </form>
</x-guest-layout>
