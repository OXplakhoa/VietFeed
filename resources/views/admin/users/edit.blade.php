<x-app-layout>
    <x-slot name="title">Sửa người dùng — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.users.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Người dùng
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.3rem">{{ $user->name }}</h3>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-5">
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <form action="{{ route('admin.users.update', $user) }}" method="POST">
                        @csrf @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Tên <span style="color:var(--accent)">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $user->name) }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Email <span style="color:var(--accent)">*</span></label>
                            <input type="email" name="email" value="{{ old('email', $user->email) }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Vai trò <span style="color:var(--accent)">*</span></label>
                            <select name="role" class="form-select @error('role') is-invalid @enderror"
                                    style="background:var(--surface-alt);border-color:var(--border);color:var(--text)"
                                    {{ $user->is(auth()->user()) ? 'disabled' : '' }}>
                                <option value="user"  {{ old('role', $user->role) === 'user'  ? 'selected' : '' }}>User — Người dùng thường</option>
                                <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin — Quản trị viên</option>
                            </select>
                            @if($user->is(auth()->user()))
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem">
                                <i class="bi bi-info-circle me-1"></i>Không thể thay đổi vai trò của tài khoản đang đăng nhập.
                            </div>
                            <input type="hidden" name="role" value="{{ $user->role }}">
                            @endif
                            @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="p-2 mb-3" style="background:var(--surface-alt);border:1px solid var(--border);border-radius:8px;font-size:.8rem;color:var(--text-muted)">
                            <i class="bi bi-shield-lock me-1"></i>Mật khẩu không thể thay đổi tại đây vì lý do bảo mật.
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn-accent" style="padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;border:none;cursor:pointer">
                                <i class="bi bi-check-lg me-1"></i>Lưu thay đổi
                            </button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px">Hủy</a>
                        </div>
                    </form>
                </div>

                {{-- User stats sidebar --}}
                <div class="mt-3 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-2">Thống kê</div>
                    @foreach([
                        ['Tham gia', $user->created_at->format('d/m/Y')],
                        ['Xác minh email', $user->email_verified_at ? $user->email_verified_at->format('d/m/Y') : 'Chưa xác minh'],
                        ['Bình luận', $user->comments()->count()],
                        ['Bài đã lưu', $user->bookmarks()->count()],
                    ] as [$k, $v])
                    <div class="d-flex justify-content-between py-1" style="border-bottom:1px solid var(--border);font-size:.82rem">
                        <span style="color:var(--text-muted)">{{ $k }}</span>
                        <span style="color:var(--text)">{{ $v }}</span>
                    </div>
                    @endforeach
                </div>

                @if(!$user->is(auth()->user()))
                <div class="mt-3 p-3" style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:12px">
                    <div style="font-size:.85rem;font-weight:500;color:#ef4444;margin-bottom:.5rem">Vùng nguy hiểm</div>
                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                          onsubmit="vfConfirmForm(event, this, 'Người dùng {{ $user->name }} sẽ bị xóa. Hành động này không thể hoàn tác.')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:.8rem;color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:.3rem .75rem;cursor:pointer">
                            <i class="bi bi-trash me-1"></i>Xóa người dùng
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
