<x-guest-layout>
    @php $title = 'Đăng ký' @endphp

    <h1 class="auth-title">Tạo tài khoản</h1>
    <p class="auth-subtitle mb-4">Tham gia VietFeed để nhận tin tức cá nhân hóa</p>

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
                   placeholder="you@example.com" required autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Mật khẩu</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="Tối thiểu 8 ký tự" required autocomplete="new-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Xác nhận mật khẩu</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" placeholder="Nhập lại mật khẩu"
                   required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-accent w-100">Tạo tài khoản</button>

        <hr class="auth-divider my-4">

        <p class="text-center mb-0" style="color: var(--text-muted); font-size: 0.875rem;">
            Đã có tài khoản?
            <a href="{{ route('login') }}" class="auth-link ms-1">Đăng nhập</a>
        </p>
    </form>
</x-guest-layout>
