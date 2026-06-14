<x-app-layout>
    <x-slot name="title">Báo cáo — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-flag me-2" style="color:var(--accent)"></i>Hộp thư báo cáo
                @if($pendingCount > 0)
                <span class="badge rounded-pill ms-2" style="background:var(--accent);color:#fff;font-size:.7rem">{{ $pendingCount }} chờ duyệt</span>
                @endif
            </h3>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('admin.reports.index') }}" class="mb-4">
            <div class="d-flex flex-wrap gap-2">
                <select name="status" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:160px">
                    <option value="">Tất cả trạng thái</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Chờ duyệt</option>
                    <option value="reviewed" {{ request('status') === 'reviewed' ? 'selected' : '' }}>Đã xem xét</option>
                    <option value="actioned" {{ request('status') === 'actioned' ? 'selected' : '' }}>Đã xử lý</option>
                    <option value="dismissed" {{ request('status') === 'dismissed' ? 'selected' : '' }}>Bác bỏ</option>
                    <option value="false_report" {{ request('status') === 'false_report' ? 'selected' : '' }}>Báo cáo sai</option>
                </select>
                <select name="reason" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:160px">
                    <option value="">Tất cả lý do</option>
                    <option value="hate_speech" {{ request('reason') === 'hate_speech' ? 'selected' : '' }}>Phát ngôn thù địch</option>
                    <option value="harassment" {{ request('reason') === 'harassment' ? 'selected' : '' }}>Quấy rối</option>
                    <option value="spam" {{ request('reason') === 'spam' ? 'selected' : '' }}>Spam</option>
                    <option value="misinformation" {{ request('reason') === 'misinformation' ? 'selected' : '' }}>Thông tin sai</option>
                    <option value="sexual_content" {{ request('reason') === 'sexual_content' ? 'selected' : '' }}>Nội dung tình dục</option>
                    <option value="other" {{ request('reason') === 'other' ? 'selected' : '' }}>Khác</option>
                </select>
                <input type="text" name="q" value="{{ request('q') }}"
                       class="form-control form-control-sm"
                       placeholder="Tìm kiếm..."
                       style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:240px">
                <button type="submit" class="btn btn-sm px-3"
                        style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                    <i class="bi bi-search"></i>
                </button>
                @if(request('status') || request('reason') || request('q'))
                <a href="{{ route('admin.reports.index') }}" class="btn btn-sm"
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
                            <th class="ps-3 py-3">Bình luận</th>
                            <th>Người vi phạm</th>
                            <th>Lý do</th>
                            <th>Số báo cáo</th>
                            <th>Trạng thái</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($reports as $report)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle;max-width:280px">
                            <div style="font-size:.85rem;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                {{ Str::limit($report->comment?->body ?? '—', 100) }}
                            </div>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem">
                                <a href="{{ route('articles.show', $report->comment?->article?->slug ?? '') }}"
                                   target="_blank" style="color:var(--text-muted);text-decoration:underline">
                                    {{ Str::limit($report->comment?->article?->title ?? '—', 50) }}
                                </a>
                            </div>
                        </td>
                        <td style="vertical-align:middle;white-space:nowrap">
                            <div style="font-size:.85rem;font-weight:500;color:var(--text)">{{ $report->comment?->user?->name ?? '—' }}</div>
                            <div style="font-size:.75rem;color:var(--text-muted)">{{ $report->comment?->user?->email ?? '' }}</div>
                        </td>
                        <td style="vertical-align:middle">
                            <span class="badge rounded-pill" style="background:var(--surface-alt);color:var(--text);font-size:.75rem;border:1px solid var(--border)">
                                {{ $report->reasonLabel() }}
                            </span>
                        </td>
                        <td style="vertical-align:middle">
                            @php
                                $count = \App\Models\Report::where('comment_id', $report->comment_id)->count();
                            @endphp
                            <span class="badge rounded-pill" style="background:{{ $count > 1 ? 'var(--accent)' : 'var(--surface-alt)' }};color:{{ $count > 1 ? '#fff' : 'var(--text-muted)' }};font-size:.75rem;border:1px solid {{ $count > 1 ? 'var(--accent)' : 'var(--border)' }}">
                                {{ $count }}
                            </span>
                        </td>
                        <td style="vertical-align:middle">
                            <span class="badge rounded-pill" style="background:{{ $report->statusColor() }}20;color:{{ $report->statusColor() }};font-size:.75rem;border:1px solid {{ $report->statusColor() }}40">
                                {{ $report->statusLabel() }}
                            </span>
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle">
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="{{ route('admin.reports.show', $report) }}"
                                   style="font-size:.8rem;color:var(--accent);text-decoration:none">
                                    <i class="bi bi-eye me-1"></i>Chi tiết
                                </a>
                                <form action="{{ route('admin.reports.destroy', $report) }}" method="POST"
                                      onsubmit="vfConfirmForm(event, this, 'Báo cáo này sẽ bị xóa vĩnh viễn.')">
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
                        <td colspan="6" class="text-center py-4" style="color:var(--text-muted)">
                            @if(request('status') || request('reason') || request('q'))
                                Không tìm thấy báo cáo nào.
                            @else
                                Chưa có báo cáo nào.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
