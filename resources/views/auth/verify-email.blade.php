<x-guest-layout>
    @php $title = 'Xác minh email' @endphp

    <div class="text-center mb-4">
        <div style="font-size: 3rem; line-height: 1;">📧</div>
    </div>

    <h1 class="auth-title text-center">Xác minh email</h1>
    <p class="auth-subtitle text-center mb-4">
        Chúng tôi đã gửi email xác minh đến địa chỉ bạn đăng ký. Vui lòng kiểm tra hộp thư và nhấn vào liên kết xác minh.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success mb-3">
            Liên kết xác minh mới đã được gửi đến email của bạn.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-accent w-100 mb-3">Gửi lại email xác minh</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn w-100" style="background: var(--surface-alt); color: var(--text-muted); border: 1px solid var(--border); border-radius: 8px;">
            Đăng xuất
        </button>
    </form>
</x-guest-layout>
