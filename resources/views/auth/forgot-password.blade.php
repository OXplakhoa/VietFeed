<x-guest-layout>
    @php $title = 'Quên mật khẩu' @endphp

    <h1 class="auth-title">Quên mật khẩu?</h1>
    <p class="auth-subtitle mb-4">Nhập email của bạn — chúng tôi sẽ gửi liên kết đặt lại mật khẩu.</p>

    @if (session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@example.com" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-accent w-100">Gửi liên kết đặt lại</button>

        <hr class="auth-divider my-4">

        <p class="text-center mb-0" style="color: var(--text-muted); font-size: 0.875rem;">
            Nhớ ra mật khẩu rồi?
            <a href="{{ route('login') }}" class="auth-link ms-1">Đăng nhập</a>
        </p>
    </form>
</x-guest-layout>
