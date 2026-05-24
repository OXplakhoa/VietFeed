<x-guest-layout>
    @php $title = 'Xác nhận mật khẩu' @endphp

    <h1 class="auth-title">Xác nhận danh tính</h1>
    <p class="auth-subtitle mb-4">Đây là khu vực bảo mật. Vui lòng xác nhận mật khẩu trước khi tiếp tục.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="mb-4">
            <label for="password" class="form-label">Mật khẩu</label>
            <div class="pw-wrap">
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

        <button type="submit" class="btn btn-accent w-100">Xác nhận</button>
    </form>
</x-guest-layout>
