<x-app-layout>
    <x-slot name="title">Hồ sơ — VietFeed</x-slot>

    <div class="container py-4" style="max-width:720px">
        <div class="section-header mb-4">
            <h2>Hồ sơ của bạn</h2>
        </div>

        {{-- Profile Info --}}
        <div class="mb-4 p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <h5 class="serif mb-3" style="color:var(--text)">Thông tin tài khoản</h5>
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf @method('PATCH')
                <div class="mb-3">
                    <label for="name" class="vf-label">Tên hiển thị</label>
                    <input type="text" id="name" name="name"
                           class="vf-form-control" value="{{ old('name', $user->name) }}" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="vf-label">Email</label>
                    <input type="email" id="email" name="email"
                           class="vf-form-control" value="{{ old('email', $user->email) }}" required>
                    @if($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail())
                    <div style="font-size:.8rem;color:var(--accent);margin-top:.35rem">
                        Email chưa xác minh.
                        <form action="{{ route('verification.send') }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" style="background:none;border:none;color:var(--accent);font-size:.8rem;padding:0;text-decoration:underline;cursor:pointer">
                                Gửi lại xác minh
                            </button>
                        </form>
                    </div>
                    @endif
                </div>
                <button type="submit" class="btn-accent">Lưu thay đổi</button>
                @if(session('status') === 'profile-updated')
                <span style="font-size:.8rem;color:#22c55e;margin-left:1rem">Đã cập nhật!</span>
                @endif
            </form>
        </div>

        {{-- Change Password --}}
        <div class="mb-4 p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <h5 class="serif mb-3" style="color:var(--text)">Đổi mật khẩu</h5>
            <form method="POST" action="{{ route('password.update') }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label for="current_password" class="vf-label">Mật khẩu hiện tại</label>
                    <input type="password" id="current_password" name="current_password" class="vf-form-control">
                    @error('current_password', 'updatePassword')
                    <div style="font-size:.8rem;color:var(--accent);margin-top:.3rem">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="password" class="vf-label">Mật khẩu mới</label>
                    <input type="password" id="password" name="password" class="vf-form-control">
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="vf-label">Xác nhận mật khẩu mới</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="vf-form-control">
                    @error('password', 'updatePassword')
                    <div style="font-size:.8rem;color:var(--accent);margin-top:.3rem">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn-accent">Đổi mật khẩu</button>
                @if(session('status') === 'password-updated')
                <span style="font-size:.8rem;color:#22c55e;margin-left:1rem">Đã cập nhật!</span>
                @endif
            </form>
        </div>

        {{-- Favourite Categories --}}
        <div class="mb-4 p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <h5 class="serif mb-1" style="color:var(--text)">Chủ đề yêu thích</h5>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">
                Feed trang chủ sẽ ưu tiên hiển thị bài viết từ các chủ đề này.
            </p>
            <a href="{{ route('onboarding.interests') }}" class="btn-outline-accent" style="text-decoration:none">
                <i class="bi bi-sliders me-1"></i>Chỉnh sửa sở thích
            </a>
        </div>

        {{-- Delete Account --}}
        <div class="p-4" style="background:var(--surface);border:1px solid rgba(230,57,70,.25);border-radius:12px">
            <h5 class="serif mb-2" style="color:var(--accent)">Xóa tài khoản</h5>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">
                Hành động này không thể hoàn tác. Tất cả dữ liệu của bạn sẽ bị xóa vĩnh viễn.
            </p>
            <button type="button" class="btn btn-sm"
                    style="background:rgba(230,57,70,.1);color:var(--accent);border:1px solid rgba(230,57,70,.3);border-radius:8px"
                    data-bs-toggle="modal" data-bs-target="#deleteModal">
                <i class="bi bi-trash me-1"></i>Xóa tài khoản
            </button>
        </div>
    </div>

    {{-- Delete Confirmation Modal --}}
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:14px">
                <div class="modal-header" style="border-bottom:1px solid var(--border)">
                    <h5 class="modal-title serif" style="color:var(--text)">Xác nhận xóa tài khoản</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="{{ route('profile.destroy') }}">
                    @csrf @method('DELETE')
                    <div class="modal-body">
                        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1rem">
                            Vui lòng nhập mật khẩu để xác nhận.
                        </p>
                        <input type="password" name="password" class="vf-form-control"
                               placeholder="Mật khẩu hiện tại" required>
                        @error('password', 'userDeletion')
                        <div style="font-size:.8rem;color:var(--accent);margin-top:.3rem">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer" style="border-top:1px solid var(--border)">
                        <button type="button" class="btn-outline-accent" data-bs-dismiss="modal" style="text-decoration:none">Hủy</button>
                        <button type="submit"
                                style="background:var(--accent);color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;font-size:.875rem">
                            Xóa tài khoản
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
