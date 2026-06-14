<x-app-layout>
    <x-slot name="title">Source Health — Quản trị VietFeed</x-slot>

    @php
        $badgeMap = [
            'healthy' => ['Ổn định', 'rgba(34,197,94,.12)', '#22c55e'],
            'warning' => ['Cảnh báo', 'rgba(251,191,36,.12)', '#f59e0b'],
            'failed' => ['Lỗi', 'rgba(239,68,68,.12)', '#ef4444'],
            'stale' => ['Cũ', 'rgba(59,130,246,.12)', '#3b82f6'],
            'critical' => ['Nghiêm trọng', 'rgba(168,85,247,.12)', '#a855f7'],
            'disabled' => ['Tắt', 'rgba(107,114,128,.12)', '#6b7280'],
            'never_fetched' => ['Chưa fetch', 'rgba(148,163,184,.12)', '#94a3b8'],
            'success' => ['Thành công', 'rgba(34,197,94,.12)', '#22c55e'],
        ];
    @endphp

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4 gap-3 flex-wrap">
            <div>
                <h3 class="mb-1" style="font-family:'Playfair Display',serif">
                    <i class="bi bi-heart-pulse me-2" style="color:var(--accent)"></i>Source Health Monitor
                </h3>
                <div style="font-size:.85rem;color:var(--text-muted)">Theo dõi tình trạng RSS, retry thủ công, test feed, xem log gần đây.</div>
            </div>
            <a href="{{ route('admin.sources.index') }}" class="btn btn-sm"
               style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border)">
                <i class="bi bi-rss me-1"></i>Quản lý nguồn tin
            </a>
        </div>

        @if(session('test_result'))
            @php $test = session('test_result'); @endphp
            <div class="mb-4 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-2">
                    <div>
                        <div style="font-weight:600;color:var(--text)">Kết quả test: {{ $test['source'] }}</div>
                        <div style="font-size:.8rem;color:var(--text-muted)">HTTP {{ $test['http_status'] ?? '—' }} · {{ $test['duration_ms'] }}ms</div>
                    </div>
                    @php [$label, $bg, $color] = $badgeMap[$test['status']] ?? ['Không rõ', 'rgba(107,114,128,.12)', '#6b7280']; @endphp
                    <span style="font-size:.75rem;background:{{ $bg }};color:{{ $color }};border:1px solid {{ $color }}33;border-radius:999px;padding:.3rem .7rem;font-weight:600">{{ $label }}</span>
                </div>
                @if($test['error_type'])
                    <div style="font-size:.85rem;color:#fca5a5">{{ $test['error_type'] }} — {{ $test['error_message'] }}</div>
                @else
                    <div class="row g-2 mb-2">
                        <div class="col-md-2"><div style="font-size:.75rem;color:var(--text-muted)">Items</div><div>{{ $test['items_found'] }}</div></div>
                        <div class="col-md-2"><div style="font-size:.75rem;color:var(--text-muted)">Hợp lệ</div><div>{{ $test['valid_items'] }}</div></div>
                        <div class="col-md-2"><div style="font-size:.75rem;color:var(--text-muted)">Không hợp lệ</div><div>{{ $test['invalid_items'] }}</div></div>
                        <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-muted)">Thiếu ảnh</div><div>{{ $test['missing_image_count'] }}</div></div>
                        <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-muted)">Lỗi parse ngày</div><div>{{ $test['date_parse_error_count'] }}</div></div>
                    </div>
                    @if(!empty($test['sample_items']))
                        <div style="font-size:.8rem;color:var(--text-muted)">Mẫu 3 bài đầu:</div>
                        <ul class="mb-0 mt-1 ps-3">
                            @foreach($test['sample_items'] as $item)
                                <li style="font-size:.84rem"><a href="{{ $item['link'] }}" target="_blank" style="color:var(--text)">{{ $item['title'] }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        @endif

        @unless($hasFetchLogsTable)
            <div class="alert alert-warning mb-4" style="border-radius:12px">
                Thiếu bảng <code>source_fetch_logs</code>. Chạy <code>php artisan migrate</code> để bật log, test RSS và retry fetch.
            </div>
        @endunless

        <div class="row g-3 mb-4">
            @foreach($summary as $status => $count)
                @php [$label, $bg, $color] = $badgeMap[$status] ?? [$status, 'rgba(107,114,128,.12)', '#6b7280']; @endphp
                <div class="col-6 col-lg">
                    <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <div style="font-size:.75rem;color:var(--text-muted)">{{ $label }}</div>
                        <div style="font-size:1.5rem;font-weight:700;color:{{ $color }}">{{ $count }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <form method="GET" class="mb-4">
            <div class="row g-2">
                <div class="col-md-4"><input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Tìm nguồn tin..." style="background:var(--surface);border-color:var(--border);color:var(--text)"></div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="">Mọi trạng thái</option>
                        @foreach(['healthy','warning','failed','stale','critical','disabled','never_fetched'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $badgeMap[$status][0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="">Mọi chủ đề</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) request('category') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="sort" class="form-select form-select-sm" style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="name" @selected(request('sort', 'name') === 'name')>Tên nguồn</option>
                        <option value="health_status" @selected(request('sort') === 'health_status')>Health</option>
                        <option value="last_successful_fetch_at" @selected(request('sort') === 'last_successful_fetch_at')>Lần thành công</option>
                        <option value="consecutive_failures" @selected(request('sort') === 'consecutive_failures')>Số lần lỗi</option>
                    </select>
                </div>
                <div class="col-md-1"><button class="btn btn-sm w-100" style="background:var(--accent);color:#fff;border:none"><i class="bi bi-search"></i></button></div>
                <div class="col-md-1"><a href="{{ route('admin.sources.health') }}" class="btn btn-sm w-100" style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border)">Reset</a></div>
            </div>
        </form>

        <div class="p-0 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color:var(--text)">
                    <thead style="background:var(--surface-alt)">
                        <tr>
                            <th class="ps-3 py-3">Nguồn</th>
                            <th>Health</th>
                            <th>Thành công gần nhất</th>
                            <th>Lỗi liên tiếp</th>
                            <th>Metrics</th>
                            <th>Lỗi gần nhất</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sources as $source)
                        @php [$label, $bg, $color] = $badgeMap[$source->health_status] ?? [$source->health_status, 'rgba(107,114,128,.12)', '#6b7280']; @endphp
                        <tr style="border-color:var(--border)">
                            <td class="ps-3 py-3">
                                <div style="font-weight:600"><a href="{{ route('admin.sources.show', $source) }}" style="color:var(--text);text-decoration:none">{{ $source->name }}</a></div>
                                <div style="font-size:.75rem;color:var(--text-muted)">{{ $source->category->name ?? '—' }} · {{ number_format($source->articles_count) }} bài</div>
                            </td>
                            <td style="vertical-align:middle"><span style="font-size:.75rem;background:{{ $bg }};color:{{ $color }};border:1px solid {{ $color }}33;border-radius:999px;padding:.3rem .7rem;font-weight:600">{{ $label }}</span></td>
                            <td style="vertical-align:middle;font-size:.82rem;color:var(--text-muted)">
                                {{ $source->last_successful_fetch_at?->diffForHumans() ?? 'Chưa có' }}
                                @if($source->last_duration_ms)
                                    <div style="font-size:.7rem">{{ number_format($source->last_duration_ms) }}ms</div>
                                @endif
                            </td>
                            <td style="vertical-align:middle">{{ $source->consecutive_failures }}</td>
                            <td style="vertical-align:middle;font-size:.78rem;color:var(--text-muted)">
                                <div>items: {{ $source->last_items_found ?? '—' }}</div>
                                <div>valid: {{ $source->last_valid_items ?? '—' }}</div>
                                <div>dup: {{ $source->last_duplicate_count ?? '—' }}</div>
                            </td>
                            <td style="vertical-align:middle;font-size:.78rem;color:var(--text-muted);max-width:260px">
                                @if($source->last_error_type)
                                    <div style="color:#fca5a5;font-weight:600">{{ $source->last_error_type }}</div>
                                    <div>{{ \Illuminate\Support\Str::limit($source->last_error_message, 110) }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pe-3 text-end" style="vertical-align:middle;white-space:nowrap">
                                <div class="d-flex flex-column gap-1 align-items-end">
                                    <form action="{{ route('admin.sources.test', $source) }}" method="POST">@csrf<button class="btn btn-sm" style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border)">Test RSS</button></form>
                                    <form action="{{ route('admin.sources.retry', $source) }}" method="POST">@csrf<button class="btn btn-sm" style="background:var(--accent);color:#fff;border:none">Retry fetch</button></form>
                                    <form action="{{ route('admin.sources.toggle-active', $source) }}" method="POST"
                                          onsubmit="vfConfirmForm(
                                              event,
                                              this,
                                              @js($source->is_active ? 'Nguồn này sẽ ngừng fetch và người dùng sẽ không thấy các tin thuộc nguồn này.' : 'Nguồn này sẽ được fetch lại và tin thuộc nguồn này sẽ hiển thị cho người dùng.'),
                                              @js($source->is_active ? 'Xác nhận tắt nguồn' : 'Xác nhận mở nguồn'),
                                              @js($source->is_active ? 'Tắt' : 'Mở'),
                                              @js($source->is_active ? '🙈' : '👁️')
                                          )">
                                        @csrf @method('PATCH')
                                        <button class="btn btn-sm" style="background:none;color:{{ $source->is_active ? '#f59e0b' : '#22c55e' }};border:1px solid var(--border)">{{ $source->is_active ? 'Tắt nguồn' : 'Mở nguồn' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted)">Không có nguồn phù hợp.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-4">{{ $sources->links() }}</div>

        <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <div class="sidebar-title mb-3">Recent fetch logs</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="color:var(--text)">
                    <thead>
                        <tr>
                            <th>Nguồn</th><th>Loại</th><th>Trạng thái</th><th>HTTP</th><th>Items</th><th>Bắt đầu</th><th>Kết thúc</th><th>Lỗi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentLogs as $log)
                            @php [$label, $bg, $color] = $badgeMap[$log->status] ?? [$log->status, 'rgba(107,114,128,.12)', '#6b7280']; @endphp
                            <tr style="border-color:var(--border)">
                                <td>{{ $log->source->name }}</td>
                                <td>{{ $log->type }}</td>
                                <td><span style="font-size:.72rem;background:{{ $bg }};color:{{ $color }};border:1px solid {{ $color }}33;border-radius:999px;padding:.2rem .5rem">{{ $label }}</span></td>
                                <td>{{ $log->http_status ?? '—' }}</td>
                                <td>{{ $log->items_found ?? '—' }}</td>
                                <td>{{ $log->started_at?->format('d/m H:i:s') ?? '—' }}</td>
                                <td>{{ $log->finished_at?->format('d/m H:i:s') ?? '—' }}</td>
                                <td style="max-width:260px;color:var(--text-muted)">{{ $log->error_type ? $log->error_type . ': ' . \Illuminate\Support\Str::limit($log->error_message, 80) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
