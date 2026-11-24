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

                        @can('super-admin')
                            <input type="hidden" name="permissions_form" value="1">
                            <div class="mb-3">
                                <label class="form-label" for="user-role">Vai trò</label>
                                <select id="user-role" name="role" class="form-select @error('role') is-invalid @enderror"
                                        style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="user" @selected(old('role', $user->role) === 'user')>User — Người dùng thường</option>
                                    <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin — Quản trị viên</option>
                                    <option value="super_admin" @selected(old('role', $user->role) === 'super_admin')>Super Admin</option>
                                </select>
                                @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <fieldset class="mb-3" id="admin-permissions">
                                <legend class="fs-6">Quyền của Admin</legend>
                                @foreach(\App\Authorization\Permissions::ALL as $permission)
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $permission }}"
                                               @checked(in_array($permission, (array) old('permissions', old('permissions_form') ? [] : $user->permissions), true))>
                                        {{ $permission }}
                                    </label>
                                @endforeach
                            </fieldset>
                            @error('permissions') <p class="text-danger">{{ $message }}</p> @enderror
                            @if($errors->has('permissions.*'))
                                <p class="text-danger">{{ $errors->first('permissions.*') }}</p>
                            @endif

                            <script>
                                const roleSelect = document.getElementById('user-role');
                                const permissions = document.getElementById('admin-permissions');
                                function updatePermissions() {
                                    permissions.disabled = roleSelect.value !== 'admin';
                                }
                                roleSelect.addEventListener('change', updatePermissions);
                                updatePermissions();
                            </script>
                        @endcan

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

                @can(\App\Authorization\Permissions::MODERATION)
                {{-- Sanction without a case --}}
                <div class="mt-3 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-2">Áp dụng biện pháp xử lý</div>
                    <form action="{{ route('admin.sanctions.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                        <select name="type" class="form-select form-select-sm mb-2" required style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            <option value="warning">Cảnh cáo</option>
                            <option value="mute">Cấm bình luận</option>
                            <option value="temporary_ban">Cấm tạm thời</option>
                            <option value="permanent_ban">Cấm vĩnh viễn</option>
                        </select>
                        <select name="duration_days" class="form-select form-select-sm mb-2" style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            <option value="3">3 ngày</option>
                            <option value="7" selected>7 ngày</option>
                            <option value="14">14 ngày</option>
                            <option value="30">30 ngày</option>
                        </select>
                        <textarea name="reason" class="vf-textarea mb-2" rows="2" required maxlength="2000" placeholder="Lý do xử lý..."></textarea>
                        <button type="submit" class="btn btn-sm" style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                            <i class="bi bi-hammer me-1"></i>Áp dụng
                        </button>
                    </form>
                </div>

                @endcan

                {{-- Billing observation --}}
                <div class="mt-3 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-2">VietFeed Pro</div>
                    <div class="d-flex justify-content-between py-1" style="border-bottom:1px solid var(--border);font-size:.82rem">
                        <span style="color:var(--text-muted)">Trạng thái</span>
                        <span style="color:{{ $user->isPro() ? 'var(--accent)' : 'var(--text)' }};font-weight:600">{{ $user->isPro() ? 'Pro active' : 'Không Pro' }}</span>
                    </div>
                    @if($user->stripeCustomerUrl())
                    <a href="{{ $user->stripeCustomerUrl() }}" target="_blank" rel="noopener" style="display:inline-flex;margin-top:.6rem;color:#60a5fa;font-size:.8rem;text-decoration:none">
                        Mở Stripe customer <i class="bi bi-box-arrow-up-right ms-1"></i>
                    </a>
                    @else
                    <div class="mt-2" style="font-size:.78rem;color:var(--text-muted)">Chưa có Stripe customer.</div>
                    @endif
                    <div class="mt-2" style="font-size:.75rem;color:var(--text-muted)">
                        Admin chỉ quan sát trạng thái, không chỉnh/hủy subscription trong VietFeed.
                    </div>
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

                @can(\App\Authorization\Permissions::MANAGE_USER, $user)
                @php($deletion = app(\App\Accounts\AccountLifecycle::class)->stateOf($user))
                <div class="mt-3 p-3" style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:12px">
                    <div style="font-size:.85rem;font-weight:500;color:#ef4444;margin-bottom:.5rem">Vùng nguy hiểm</div>
                    @if($deletion)
                        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.5rem">
                            @if($deletion['state'] === 'purging')
                                Tài khoản đang được xóa vĩnh viễn, không thể khôi phục.
                            @else
                                Đã lên lịch xóa ({{ $deletion['origin'] === 'admin' ? 'bởi quản trị viên' : 'do người dùng yêu cầu' }}).
                                Xóa vĩnh viễn sau {{ $deletion['purge_after']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.
                            @endif
                        </div>
                        @if($deletion['state'] === 'pending' && $deletion['origin'] === 'admin')
                        <form action="{{ route('admin.users.recover', $user) }}" method="POST">
                            @csrf
                            <button type="submit" style="font-size:.8rem;color:var(--text);background:var(--surface-alt);border:1px solid var(--border);border-radius:6px;padding:.3rem .75rem;cursor:pointer">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Khôi phục
                            </button>
                        </form>
                        @endif
                    @else
                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                          onsubmit="vfConfirmForm(event, this, 'Tài khoản {{ $user->name }} sẽ bị khóa ngay và xóa vĩnh viễn sau '+{{ \App\Accounts\AccountLifecycle::recoveryDays() }}+' ngày. Chỉ quản trị viên mới khôi phục được trong thời gian này.')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:.8rem;color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:.3rem .75rem;cursor:pointer">
                            <i class="bi bi-trash me-1"></i>Lên lịch xóa
                        </button>
                    </form>
                    @endif
                </div>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
