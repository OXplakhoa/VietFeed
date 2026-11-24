<x-app-layout>
    <x-slot name="title">Khôi phục tài khoản — VietFeed</x-slot>

    <div class="container py-5" style="max-width:640px">
        <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <h4 class="serif mb-3">Tài khoản đang chờ xóa</h4>

            @if(session('error'))
                <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
            @endif

            @if($state['state'] === 'purging')
                <p style="color:var(--text-muted)">Tài khoản của bạn đang được xóa vĩnh viễn nên không thể khôi phục nữa.</p>
            @elseif($state['origin'] === 'admin')
                <p style="color:var(--text-muted)">
                    Quản trị viên đã lên lịch xóa tài khoản này. Tài khoản sẽ bị xóa vĩnh viễn sau
                    <strong>{{ $state['purge_after']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</strong>.
                    Chỉ quản trị viên mới có thể khôi phục; hãy liên hệ quản trị viên nếu đây là nhầm lẫn.
                </p>
            @else
                <p style="color:var(--text-muted)">
                    Bạn đã yêu cầu xóa tài khoản. Bạn có thể khôi phục tài khoản đến hết
                    <strong>{{ $state['purge_after']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</strong>;
                    sau thời điểm này dữ liệu sẽ bị xóa vĩnh viễn.
                </p>
                <p style="font-size:.85rem;color:var(--text-muted)">
                    Gói VietFeed Pro (nếu có) đã bị hủy và sẽ không được kích hoạt lại khi khôi phục; bạn có thể đăng ký lại sau.
                </p>
                <form method="POST" action="{{ route('account.recover.store') }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn-accent" style="padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;border:none">
                        Khôi phục tài khoản
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px">Đăng xuất</button>
            </form>
        </div>
    </div>
</x-app-layout>
