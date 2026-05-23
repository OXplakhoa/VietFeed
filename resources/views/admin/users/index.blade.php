<x-app-layout>
    <x-slot name="title">Người dùng — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-people me-2" style="color:var(--accent)"></i>Người dùng
                <span style="font-size:.9rem;font-weight:400;color:var(--text-muted)">({{ number_format($users->total()) }})</span>
            </h3>
        </div>

        {{-- Search --}}
        <form method="GET" action="{{ route('admin.users.index') }}" class="mb-4">
            <div class="d-flex gap-2">
                <input type="text" name="q" value="{{ request('q') }}"
                       class="form-control form-control-sm"
                       placeholder="Tìm theo tên hoặc email…"
                       style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:360px">
                <button type="submit" class="btn btn-sm px-3"
                        style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                    <i class="bi bi-search"></i>
                </button>
                @if(request('q'))
                <a href="{{ route('admin.users.index') }}" class="btn btn-sm"
                   style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:6px">
                    Xóa bộ lọc
                </a>
                @endif
            </div>
        </form>

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color:var(--text)">
                    <thead style="background:var(--surface-alt);font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                        <tr>
                            <th class="ps-3 py-3">Người dùng</th>
                            <th>Vai trò</th>
                            <th>Xác minh</th>
                            <th><i class="bi bi-chat-dots"></i></th>
                            <th><i class="bi bi-bookmark"></i></th>
                            <th>Tham gia</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($users as $u)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle">
                            <div style="font-size:.875rem;font-weight:500;color:var(--text)">
                                {{ $u->name }}
                                @if($u->is(auth()->user()))
                                <span style="font-size:.7rem;color:var(--accent);margin-left:.3rem">(bạn)</span>
                                @endif
                            </div>
                            <div style="font-size:.75rem;color:var(--text-secondary)">{{ $u->email }}</div>
                        </td>
                        <td style="vertical-align:middle">
                            @if($u->isAdmin())
                            <span style="font-size:.75rem;background:rgba(230,57,70,.12);color:var(--accent);border:1px solid rgba(230,57,70,.3);border-radius:6px;padding:.2rem .55rem;font-weight:500">Admin</span>
                            @else
                            <span style="font-size:.75rem;background:rgba(96,165,250,.1);color:#60a5fa;border:1px solid rgba(96,165,250,.25);border-radius:6px;padding:.2rem .55rem;font-weight:500">User</span>
                            @endif
                        </td>
                        <td style="vertical-align:middle">
                            @if($u->email_verified_at)
                            <i class="bi bi-check-circle-fill" style="color:#22c55e;font-size:.9rem" title="Đã xác minh"></i>
                            @else
                            <i class="bi bi-x-circle" style="color:var(--text-muted);font-size:.9rem" title="Chưa xác minh"></i>
                            @endif
                        </td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ $u->comments_count }}</td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ $u->bookmarks_count }}</td>
                        <td style="vertical-align:middle;font-size:.78rem;color:var(--text-secondary);white-space:nowrap">
                            {{ $u->created_at->format('d/m/Y') }}
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle;white-space:nowrap">
                            <a href="{{ route('admin.users.edit', $u) }}"
                               style="color:#60a5fa;font-size:.8rem;text-decoration:none;margin-right:.75rem">
                                <i class="bi bi-pencil me-1"></i>Sửa
                            </a>
                            @if(!$u->is(auth()->user()))
                            <form action="{{ route('admin.users.destroy', $u) }}" method="POST" class="d-inline"
                                  onsubmit="vfConfirmForm(event, this, 'Người dùng {{ $u->name }} sẽ bị xóa vĩnh viễn.')">
                                @csrf @method('DELETE')
                                <button type="submit" style="color:#ef4444;font-size:.8rem;background:none;border:none;padding:0;cursor:pointer">
                                    <i class="bi bi-trash me-1"></i>Xóa
                                </button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-4" style="color:var(--text-muted)">Không tìm thấy người dùng.</td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $users->links() }}</div>
    </div>
</x-app-layout>
