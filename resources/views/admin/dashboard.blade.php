<x-app-layout>
    <x-slot name="title">Quản trị — VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="section-header mb-4">
            <h2><i class="bi bi-speedometer2 me-2" style="color:var(--accent)"></i>Bảng điều khiển</h2>
        </div>

        {{-- Stats Cards --}}
        <div class="row g-3 mb-4">
            @foreach([
                ['label' => 'Bài viết',     'value' => number_format($stats['articles']),  'icon' => 'bi-newspaper',    'color' => '#60a5fa'],
                ['label' => 'Người dùng',   'value' => number_format($stats['users']),     'icon' => 'bi-people',       'color' => '#34d399'],
                ['label' => 'Bình luận',    'value' => number_format($stats['comments']),  'icon' => 'bi-chat-dots',    'color' => '#fbbf24'],
                ['label' => 'Đã lưu',       'value' => number_format($stats['bookmarks']), 'icon' => 'bi-bookmark',     'color' => '#a78bfa'],
                ['label' => 'Nguồn tin',    'value' => number_format($stats['sources']),   'icon' => 'bi-rss',          'color' => 'var(--accent)'],
            ] as $stat)
            <div class="col-6 col-lg">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center gap-3">
                        <div style="background:rgba(96,165,250,.08);border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="bi {{ $stat['icon'] }}" style="font-size:1.3rem;color:{{ $stat['color'] }}"></i>
                        </div>
                        <div>
                            <div style="font-size:1.4rem;font-weight:700;font-family:'Playfair Display',serif;color:var(--text)">
                                {{ $stat['value'] }}
                            </div>
                            <div style="font-size:.75rem;color:var(--text-muted)">{{ $stat['label'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Charts Row 1: Articles / Users over time --}}
        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Bài viết mới (30 ngày qua)</div>
                    <canvas id="chartArticles" height="120"></canvas>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Người dùng mới (30 ngày qua)</div>
                    <canvas id="chartUsers" height="120"></canvas>
                </div>
            </div>
        </div>

        {{-- Charts Row 2: Per category + Per source --}}
        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Bài viết theo chủ đề</div>
                    <canvas id="chartCategory" height="200"></canvas>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Top 10 nguồn tin theo số bài</div>
                    <canvas id="chartSource" height="200"></canvas>
                </div>
            </div>
        </div>

        {{-- Bottom Row: Most bookmarked + Recent comments + Recent articles --}}
        <div class="row g-4 mb-4">
            {{-- Most bookmarked --}}
            <div class="col-lg-4">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Top bài viết được lưu</div>
                    @forelse($mostBookmarked as $i => $a)
                    <div class="d-flex align-items-start gap-2 py-2" style="border-bottom:1px solid var(--border)">
                        <span style="font-weight:700;color:var(--accent);font-size:.85rem;flex-shrink:0;width:20px">{{ $i + 1 }}</span>
                        <div class="flex-fill" style="min-width:0">
                            <a href="{{ route('articles.show', $a->slug) }}"
                               target="_blank"
                               style="font-size:.8rem;color:var(--text);text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                {{ $a->title }}
                            </a>
                            <div style="font-size:.7rem;color:var(--text-secondary);margin-top:.15rem">
                                <i class="bi bi-bookmark-fill me-1" style="color:var(--accent)"></i>{{ $a->bookmarks_count }}
                            </div>
                        </div>
                    </div>
                    @empty
                    <p style="color:var(--text-muted);font-size:.85rem">Chưa có dữ liệu.</p>
                    @endforelse
                </div>
            </div>

            {{-- Recent Comments --}}
            <div class="col-lg-4">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="sidebar-title mb-0">Bình luận mới nhất</div>
                        <a href="{{ route('admin.comments.index') }}" style="font-size:.75rem;color:var(--accent);text-decoration:none">Xem tất cả →</a>
                    </div>
                    @forelse($recentComments as $c)
                    <div class="py-2" style="border-bottom:1px solid var(--border)">
                        <div class="d-flex align-items-center gap-1 mb-1">
                            <i class="bi bi-person-circle" style="color:var(--text-muted);font-size:.8rem"></i>
                            <span style="font-size:.75rem;font-weight:500;color:var(--text)">{{ $c->user?->name ?? 'Ẩn danh' }}</span>
                            <span style="font-size:.7rem;color:var(--text-secondary)">· {{ $c->created_at->diffForHumans() }}</span>
                        </div>
                        <div style="font-size:.8rem;color:var(--text-secondary);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                            {{ $c->body }}
                        </div>
                        <form action="{{ route('admin.comments.destroy', $c) }}" method="POST" class="mt-1"
                              onsubmit="vfConfirmForm(event, this, 'Bình luận này sẽ bị xóa vĩnh viễn.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm p-0" style="font-size:.7rem;color:#ef4444;background:none;border:none">
                                <i class="bi bi-trash me-1"></i>Xóa
                            </button>
                        </form>
                    </div>
                    @empty
                    <p style="color:var(--text-muted);font-size:.85rem">Chưa có bình luận.</p>
                    @endforelse
                </div>
            </div>

            {{-- Source Health --}}
            <div class="col-lg-4">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="sidebar-title mb-0">Trạng thái nguồn tin</div>
                        <a href="{{ route('admin.sources.index') }}" style="font-size:.75rem;color:var(--accent);text-decoration:none">Quản lý →</a>
                    </div>
                    <div style="max-height:320px;overflow-y:auto">
                        @foreach($sources as $src)
                        <div class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px solid var(--border)">
                            <span class="d-inline-block rounded-circle" style="width:7px;height:7px;flex-shrink:0;background:{{ $src->is_active ? '#22c55e' : '#6b7280' }}"></span>
                            <div class="flex-fill" style="min-width:0">
                                <div style="font-size:.78rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $src->name }}</div>
                                <div style="font-size:.68rem;color:var(--text-muted)">
                                    {{ $src->last_fetched_at ? $src->last_fetched_at->diffForHumans() : 'Chưa lấy' }}
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Articles --}}
        <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="sidebar-title mb-0">Bài viết mới nhất</div>
                <a href="{{ route('admin.articles.index') }}" style="font-size:.8rem;color:var(--accent);text-decoration:none">Quản lý bài viết →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="color:var(--text)">
                    <tbody>
                    @foreach($recentArticles as $a)
                    <tr style="border-color:var(--border)">
                        <td style="font-size:.85rem;max-width:400px">
                            <a href="{{ route('articles.show', $a->slug) }}" target="_blank"
                               style="color:var(--text);text-decoration:none">
                                {{ Str::limit($a->title, 70) }}
                            </a>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-secondary);white-space:nowrap">{{ $a->source->name }}</td>
                        <td style="font-size:.75rem;color:var(--text-secondary);white-space:nowrap">{{ $a->created_at->diffForHumans() }}</td>
                        <td style="white-space:nowrap">
                            <a href="{{ route('admin.articles.edit', $a) }}"
                               style="font-size:.75rem;color:var(--accent);text-decoration:none">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
        const textColor  = isDark ? '#C4C4D4' : '#6B6B78';
        const accent     = '#E63946';

        Chart.defaults.color = textColor;
        Chart.defaults.borderColor = gridColor;
        Chart.defaults.font.family = "'Be Vietnam Pro', sans-serif";
        Chart.defaults.font.size = 11;

        const dates    = @json($chartDates);
        const articles = @json($chartArticles);
        const users    = @json($chartUsers);

        // Articles over time
        new Chart(document.getElementById('chartArticles'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Bài viết',
                    data: articles,
                    borderColor: '#60a5fa',
                    backgroundColor: 'rgba(96,165,250,.12)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        // Users over time
        new Chart(document.getElementById('chartUsers'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Người dùng',
                    data: users,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52,211,153,.12)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxTicksLimit: 8 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        // Per category donut
        const catData   = @json($perCategory->pluck('articles_count'));
        const catLabels = @json($perCategory->pluck('name'));
        const palette   = ['#E63946','#60a5fa','#34d399','#fbbf24','#a78bfa','#f472b6','#fb923c','#38bdf8'];
        new Chart(document.getElementById('chartCategory'), {
            type: 'doughnut',
            data: {
                labels: catLabels,
                datasets: [{
                    data: catData,
                    backgroundColor: palette,
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } }
                },
                cutout: '62%'
            }
        });

        // Per source horizontal bar
        const srcLabels = @json($perSource->pluck('name'));
        const srcData   = @json($perSource->pluck('articles_count'));
        new Chart(document.getElementById('chartSource'), {
            type: 'bar',
            data: {
                labels: srcLabels,
                datasets: [{
                    label: 'Bài viết',
                    data: srcData,
                    backgroundColor: 'rgba(230,57,70,.7)',
                    borderRadius: 4,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true },
                    y: { ticks: { font: { size: 10 } } }
                }
            }
        });
    })();
    </script>
    @endpush
</x-app-layout>
