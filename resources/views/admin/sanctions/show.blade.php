<x-app-layout>
    <x-slot name="title">Chi tiết biện pháp xử lý #{{ $sanction->id }} — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="mb-4">
            <a href="{{ route('admin.sanctions.index') }}" style="font-size:.85rem;color:var(--text-muted);text-decoration:none">
                <i class="bi bi-arrow-left me-1"></i>Quay lại danh sách biện pháp xử lý
            </a>
        </div>

        <div class="row g-4">
            {{-- Sanction Details --}}
            <div class="col-lg-8">
                <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h4 class="serif mb-0" style="color:var(--text)">Biện pháp xử lý #{{ $sanction->id }}</h4>
                        <div class="d-flex gap-2">
                            <span class="badge rounded-pill" style="background:{{ $sanction->typeColor() }}20;color:{{ $sanction->typeColor() }};font-size:.8rem;border:1px solid {{ $sanction->typeColor() }}40">
                                {{ $sanction->typeLabel() }}
                            </span>
                            @if($sanction->isCurrentlyActive())
                                <span class="badge rounded-pill" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.8rem;border:1px solid rgba(34,197,94,.3)">Hiệu lực</span>
                            @else
                                <span class="badge rounded-pill" style="background:rgba(107,114,128,.12);color:var(--text-muted);font-size:.8rem;border:1px solid rgba(107,114,128,.3)">Hết hiệu lực</span>
                            @endif
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Người dùng</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $sanction->user?->name ?? '—' }}</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">{{ $sanction->user?->email ?? '' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Quản trị viên áp dụng</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $sanction->admin?->name ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Thời gian áp dụng</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $sanction->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Thời hạn</div>
                            <div style="font-size:.9rem;color:var(--text)">
                                @if($sanction->expires_at)
                                    {{ $sanction->expires_at->format('d/m/Y H:i') }}
                                    <span class="ms-1" style="color:var(--text-muted)">({{ $sanction->durationLabel() }})</span>
                                @else
                                    {{ $sanction->durationLabel() }}
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Lý do</div>
                        <div class="p-3" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text);line-height:1.6">
                            {{ $sanction->reason }}
                        </div>
                    </div>

                    @if($sanction->report)
                    <div class="mb-3">
                        <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Báo cáo liên quan</div>
                        <a href="{{ route('admin.reports.show', $sanction->report) }}" style="font-size:.85rem;color:var(--accent);text-decoration:none">
                            <i class="bi bi-flag me-1"></i>Báo cáo #{{ $sanction->report->id }} — {{ $sanction->report->reasonLabel() }}
                        </a>
                    </div>
                    @endif
                </div>

                @if($sanction->report && $sanction->report->comment)
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <h5 class="serif mb-3" style="color:var(--text)">Bình luận vi phạm</h5>
                    <div class="d-flex gap-2 align-items-start">
                        <div class="comment-avatar">
                            <i class="bi bi-person"></i>
                        </div>
                        <div class="flex-fill">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong style="font-size:.875rem;color:var(--text)">{{ $sanction->report->comment->user?->name ?? '—' }}</strong>
                                <span style="font-size:.75rem;color:var(--text-muted)">{{ $sanction->report->comment->created_at?->diffForHumans() ?? '' }}</span>
                            </div>
                            <div style="font-size:.9rem;color:var(--text);line-height:1.6">
                                {{ $sanction->report->comment->body }}
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('articles.show', $sanction->report->comment->article?->slug ?? '') }}"
                       target="_blank" class="d-block mt-3" style="font-size:.85rem;color:var(--accent);text-decoration:none">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Xem bài viết
                    </a>
                </div>
                @endif
            </div>

            {{-- Sidebar: Appeal Management --}}
            <div class="col-lg-4">
                <div class="sticky-top" style="top:90px;z-index:100">
                    <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-3" style="color:var(--text)">Kháng cáo</h5>

                        @if($sanction->appeal_status === 'none')
                            <p style="font-size:.85rem;color:var(--text-muted)">Người dùng chưa gửi kháng cáo.</p>
                        @elseif($sanction->appeal_status === 'pending')
                            <div class="mb-3">
                                <span class="badge rounded-pill" style="background:rgba(245,158,11,.12);color:#f59e0b;font-size:.8rem;border:1px solid rgba(245,158,11,.3)">Chờ duyệt</span>
                            </div>
                            @if($sanction->appeal_message)
                            <div class="p-3 mb-3" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text);line-height:1.6">
                                <strong style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Nội dung kháng cáo</strong><br>
                                {{ $sanction->appeal_message }}
                            </div>
                            @endif
                            <div class="d-flex gap-2">
                                <form action="{{ route('admin.sanctions.update', $sanction) }}" method="POST" class="m-0">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="appeal_status" value="approved">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:#22c55e;color:#fff;border:none;border-radius:6px">
                                        <i class="bi bi-check me-1"></i>Chấp nhận
                                    </button>
                                </form>
                                <form action="{{ route('admin.sanctions.update', $sanction) }}" method="POST" class="m-0">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="appeal_status" value="denied">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:#ef4444;color:#fff;border:none;border-radius:6px">
                                        <i class="bi bi-x me-1"></i>Từ chối
                                    </button>
                                </form>
                            </div>
                        @elseif($sanction->appeal_status === 'approved')
                            <div class="mb-3">
                                <span class="badge rounded-pill" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.8rem;border:1px solid rgba(34,197,94,.3)">Đã chấp nhận</span>
                            </div>
                            <p style="font-size:.85rem;color:var(--text-muted)">Biện pháp xử lý đã bị hủy bỏ.</p>
                            @if($sanction->appeal_message)
                            <div class="p-3" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text);line-height:1.6">
                                <strong style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Nội dung kháng cáo</strong><br>
                                {{ $sanction->appeal_message }}
                            </div>
                            @endif
                        @elseif($sanction->appeal_status === 'denied')
                            <div class="mb-3">
                                <span class="badge rounded-pill" style="background:rgba(239,68,68,.12);color:#ef4444;font-size:.8rem;border:1px solid rgba(239,68,68,.3)">Bị từ chối</span>
                            </div>
                            @if($sanction->appeal_message)
                            <div class="p-3" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text);line-height:1.6">
                                <strong style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Nội dung kháng cáo</strong><br>
                                {{ $sanction->appeal_message }}
                            </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
