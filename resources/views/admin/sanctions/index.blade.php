<x-app-layout>
    <x-slot name="title">Biện pháp xử lý — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-shield-exclamation me-2" style="color:var(--accent)"></i>Biện pháp xử lý
                @if($pendingAppealsCount > 0)
                <span class="badge rounded-pill ms-2" style="background:#f59e0b;color:#fff;font-size:.7rem">{{ $pendingAppealsCount }} kháng cáo</span>
                @endif
            </h3>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('admin.sanctions.index') }}" class="mb-4">
            <div class="d-flex flex-wrap gap-2">
                <select name="type" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:160px">
                    <option value="">Tất cả loại</option>
                    <option value="warning" {{ request('type') === 'warning' ? 'selected' : '' }}>Cảnh cáo</option>
                    <option value="mute" {{ request('type') === 'mute' ? 'selected' : '' }}>Cấm bình luận</option>
                    <option value="temporary_ban" {{ request('type') === 'temporary_ban' ? 'selected' : '' }}>Cấm tạm thời</option>
                    <option value="permanent_ban" {{ request('type') === 'permanent_ban' ? 'selected' : '' }}>Cấm vĩnh viễn</option>
                </select>
                <select name="status" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:160px">
                    <option value="">Tất cả trạng thái</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Đang hiệu lực</option>
                    <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Hết hiệu lực</option>
                    <option value="pending_appeal" {{ request('status') === 'pending_appeal' ? 'selected' : '' }}>Chờ kháng cáo</option>
                </select>
                <input type="text" name="q" value="{{ request('q') }}"
                       class="form-control form-control-sm"
                       placeholder="Tìm kiếm..."
                       style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:240px">
                <button type="submit" class="btn btn-sm px-3"
                        style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                    <i class="bi bi-search"></i>
                </button>
                @if(request('type') || request('status') || request('q'))
                <a href="{{ route('admin.sanctions.index') }}" class="btn btn-sm"
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
                            <th>Loại</th>
                            <th>Lý do</th>
                            <th>Thời hạn</th>
                            <th>Trạng thái</th>
                            <th>Kháng cáo</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sanctions as $sanction)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle;white-space:nowrap">
                            <div style="font-size:.85rem;font-weight:500;color:var(--text)">{{ $sanction->user?->name ?? '—' }}</div>
                            <div style="font-size:.75rem;color:var(--text-muted)">{{ $sanction->user?->email ?? '' }}</div>
                        </td>
                        <td style="vertical-align:middle">
                            <span class="badge rounded-pill" style="background:{{ $sanction->typeColor() }}20;color:{{ $sanction->typeColor() }};font-size:.75rem;border:1px solid {{ $sanction->typeColor() }}40">
                                {{ $sanction->typeLabel() }}
                            </span>
                        </td>
                        <td style="vertical-align:middle;max-width:250px">
                            <div style="font-size:.85rem;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                {{ $sanction->reason }}
                            </div>
                        </td>
                        <td style="vertical-align:middle;white-space:nowrap;font-size:.8rem;color:var(--text-muted)">
                            @if($sanction->expires_at)
                                {{ $sanction->expires_at->format('d/m/Y') }}
                                <br><span style="font-size:.75rem">({{ $sanction->durationLabel() }})</span>
                            @else
                                {{ $sanction->durationLabel() }}
                            @endif
                        </td>
                        <td style="vertical-align:middle">
                            @if($sanction->isCurrentlyActive())
                                <span class="badge rounded-pill" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.75rem;border:1px solid rgba(34,197,94,.3)">Hiệu lực</span>
                            @else
                                <span class="badge rounded-pill" style="background:rgba(107,114,128,.12);color:var(--text-muted);font-size:.75rem;border:1px solid rgba(107,114,128,.3)">Hết hiệu lực</span>
                            @endif
                        </td>
                        <td style="vertical-align:middle">
                            @if($sanction->appeal_status === 'pending')
                                <span class="badge rounded-pill" style="background:rgba(245,158,11,.12);color:#f59e0b;font-size:.75rem;border:1px solid rgba(245,158,11,.3)">Chờ duyệt</span>
                            @elseif($sanction->appeal_status === 'approved')
                                <span class="badge rounded-pill" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.75rem;border:1px solid rgba(34,197,94,.3)">Đã chấp nhận</span>
                            @elseif($sanction->appeal_status === 'denied')
                                <span class="badge rounded-pill" style="background:rgba(239,68,68,.12);color:#ef4444;font-size:.75rem;border:1px solid rgba(239,68,68,.3)">Bị từ chối</span>
                            @else
                                <span style="font-size:.75rem;color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle">
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="{{ route('admin.sanctions.show', $sanction) }}"
                                   style="font-size:.8rem;color:var(--accent);text-decoration:none">
                                    <i class="bi bi-eye me-1"></i>Chi tiết
                                </a>
                                <form action="{{ route('admin.sanctions.destroy', $sanction) }}" method="POST"
                                      onsubmit="vfConfirmForm(event, this, 'Biện pháp xử lý này sẽ bị xóa vĩnh viễn.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" style="font-size:.8rem;color:#ef4444;background:none;border:none;padding:0;cursor:pointer">
                                        <i class="bi bi-trash me-1"></i>Xóa
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-4" style="color:var(--text-muted)">
                            @if(request('type') || request('status') || request('q'))
                                Không tìm thấy biện pháp xử lý nào.
                            @else
                                Chưa có biện pháp xử lý nào.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $sanctions->links() }}</div>
    </div>
</x-app-layout>
