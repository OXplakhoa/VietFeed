<x-guest-layout>
    @php $title = 'Đặt lại mật khẩu' @endphp

    <h1 class="auth-title">Đặt lại mật khẩu</h1>
    <p class="auth-subtitle mb-4">Tạo mật khẩu mới cho tài khoản của bạn.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Mật khẩu mới</label>
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
            <label for="password_confirmation" class="form-label">Xác nhận mật khẩu mới</label>
            <div class="pw-wrap">
                <input id="password_confirmation" type="password" name="password_confirmation"
                       class="form-control" placeholder="Nhập lại mật khẩu"
                       required autocomplete="new-password">
                <button type="button" class="pw-toggle" tabindex="-1" aria-label="Hiện/ẩn mật khẩu">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-accent w-100">Đặt lại mật khẩu</button>
    </form>
</x-guest-layout>
