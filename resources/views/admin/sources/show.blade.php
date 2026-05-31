<x-app-layout>
    <x-slot name="title">{{ $source->name }} — Quản trị VietFeed</x-slot>

    @php
        $statusMap = [
            'healthy' => ['Ổn định', 'rgba(34,197,94,.12)', '#22c55e'],
            'warning' => ['Cảnh báo', 'rgba(251,191,36,.12)', '#f59e0b'],
            'failed' => ['Lỗi', 'rgba(239,68,68,.12)', '#ef4444'],
            'stale' => ['Cũ', 'rgba(59,130,246,.12)', '#3b82f6'],
            'critical' => ['Nghiêm trọng', 'rgba(168,85,247,.12)', '#a855f7'],
            'disabled' => ['Tắt', 'rgba(107,114,128,.12)', '#6b7280'],
            'never_fetched' => ['Chưa fetch', 'rgba(148,163,184,.12)', '#94a3b8'],
            'success' => ['Thành công', 'rgba(34,197,94,.12)', '#22c55e'],
        ];
        [$label, $bg, $color] = $statusMap[$source->health_status] ?? ['Không rõ', 'rgba(107,114,128,.12)', '#6b7280'];
    @endphp

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
            <a href="{{ route('admin.sources.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Nguồn tin
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.3rem">{{ $source->name }}</h3>
            <span style="font-size:.75rem;background:{{ $bg }};color:{{ $color }};border:1px solid {{ $color }}33;border-radius:999px;padding:.3rem .7rem;font-weight:600">{{ $label }}</span>
            <div class="ms-auto d-flex gap-2 flex-wrap">
                <form action="{{ route('admin.sources.test', $source) }}" method="POST">@csrf<button class="btn btn-sm" style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border)">Test RSS</button></form>
                <form action="{{ route('admin.sources.retry', $source) }}" method="POST">@csrf<button class="btn btn-sm" style="background:var(--accent);color:#fff;border:none">Retry fetch</button></form>
                <form action="{{ route('admin.sources.toggle-active', $source) }}" method="POST">@csrf @method('PATCH')<button class="btn btn-sm" style="background:none;color:{{ $source->is_active ? '#f59e0b' : '#22c55e' }};border:1px solid var(--border)">{{ $source->is_active ? 'Tắt nguồn' : 'Bật nguồn' }}</button></form>
                <a href="{{ route('admin.sources.edit', $source) }}" class="btn btn-sm"
                   style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px;text-decoration:none">
                    <i class="bi bi-pencil me-1"></i>Sửa
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="row g-3">
                        @foreach([
                            ['Chủ đề', $source->category->name ?? '—'],
                            ['URL trang chủ', $source->url],
                            ['RSS Feed', $source->feed_url],
                            ['Lần fetch thành công', $source->last_successful_fetch_at?->format('d/m/Y H:i:s') ?? 'Chưa có'],
                            ['Lần fetch lỗi', $source->last_failed_fetch_at?->format('d/m/Y H:i:s') ?? 'Chưa có'],
                            ['Lỗi liên tiếp', $source->consecutive_failures],
                        ] as [$metaLabel, $metaValue])
                            <div class="col-md-6">
                                <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.15rem">{{ $metaLabel }}</div>
                                <div style="font-size:.875rem;color:var(--text);word-break:break-all">{{ $metaValue }}</div>
                            </div>
                        @endforeach
                    </div>
                    @if($source->last_error_type)
                        <div class="mt-3 p-3" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px">
                            <div style="font-size:.75rem;color:#fca5a5">Lỗi gần nhất</div>
                            <div style="font-size:.9rem;color:#fecaca;font-weight:600">{{ $source->last_error_type }}</div>
                            <div style="font-size:.82rem;color:#fca5a5">{{ $source->last_error_message }}</div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div style="font-size:2.5rem;font-weight:700;font-family:'Playfair Display',serif;color:var(--accent)">{{ number_format($articles->total()) }}</div>
                    <div style="font-size:.85rem;color:var(--text-muted)" class="mb-3">Bài viết đã lấy</div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Items gần nhất: {{ $source->last_items_found ?? '—' }}</div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Valid gần nhất: {{ $source->last_valid_items ?? '—' }}</div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Duplicate gần nhất: {{ $source->last_duplicate_count ?? '—' }}</div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Duration: {{ $source->last_duration_ms ? number_format($source->last_duration_ms) . 'ms' : '—' }}</div>
                </div>
            </div>
        </div>

        <div class="p-3 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <div class="sidebar-title mb-3">Recent fetch logs</div>
            @unless($hasFetchLogsTable)
                <div class="alert alert-warning mb-3" style="border-radius:10px">
                    Thiếu bảng <code>source_fetch_logs</code>. Chạy <code>php artisan migrate</code> để bật log, test RSS và retry fetch.
                </div>
            @endunless
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="color:var(--text)">
                    <thead><tr><th>Loại</th><th>Trạng thái</th><th>HTTP</th><th>Items</th><th>Created</th><th>Updated</th><th>Started</th><th>Error</th></tr></thead>
                    <tbody>
                    @forelse($recentLogs as $log)
                        @php [$logLabel, $logBg, $logColor] = $statusMap[$log->status] ?? [$log->status, 'rgba(107,114,128,.12)', '#6b7280']; @endphp
                        <tr style="border-color:var(--border)">
                            <td>{{ $log->type }}</td>
                            <td><span style="font-size:.72rem;background:{{ $logBg }};color:{{ $logColor }};border:1px solid {{ $logColor }}33;border-radius:999px;padding:.2rem .5rem">{{ $logLabel }}</span></td>
                            <td>{{ $log->http_status ?? '—' }}</td>
                            <td>{{ $log->items_found ?? '—' }}</td>
                            <td>{{ $log->items_created ?? '—' }}</td>
                            <td>{{ $log->items_updated ?? '—' }}</td>
                            <td>{{ $log->started_at?->format('d/m H:i:s') ?? '—' }}</td>
                            <td style="max-width:260px;color:var(--text-muted)">{{ $log->error_type ? $log->error_type . ': ' . \Illuminate\Support\Str::limit($log->error_message, 80) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted)">Chưa có log.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="px-3 py-2" style="border-bottom:1px solid var(--border)">
                <span style="font-size:.85rem;font-weight:500;color:var(--text)">Bài viết từ nguồn này</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="color:var(--text)">
                    <tbody>
                    @forelse($articles as $a)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-2" style="font-size:.85rem;max-width:400px">
                            <a href="{{ route('articles.show', $a->slug) }}" target="_blank" style="color:var(--text);text-decoration:none">
                                {{ Str::limit($a->title, 70) }}
                            </a>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-secondary);white-space:nowrap"><i class="bi bi-bookmark me-1"></i>{{ $a->bookmarks_count }}</td>
                        <td style="font-size:.75rem;color:var(--text-secondary);white-space:nowrap">{{ $a->published_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="pe-3" style="white-space:nowrap"><a href="{{ route('admin.articles.edit', $a) }}" style="font-size:.75rem;color:#60a5fa;text-decoration:none"><i class="bi bi-pencil"></i></a></td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center py-4" style="color:var(--text-muted)">Chưa có bài viết.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $articles->links() }}</div>
    </div>
</x-app-layout>
