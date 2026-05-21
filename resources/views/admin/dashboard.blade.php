<x-app-layout>
    <x-slot name="title">Quản trị — VietFeed</x-slot>

    <div class="container-xl py-4">
        <div class="section-header mb-4">
            <h2><i class="bi bi-speedometer2 me-2" style="color:var(--accent)"></i>Bảng điều khiển</h2>
        </div>

        {{-- Stats Cards --}}
        <div class="row g-3 mb-4">
            @foreach([
                ['label' => 'Bài viết', 'value' => number_format($stats['articles']), 'icon' => 'bi-newspaper', 'color' => '#60a5fa'],
                ['label' => 'Người dùng', 'value' => number_format($stats['users']), 'icon' => 'bi-people', 'color' => '#34d399'],
                ['label' => 'Bình luận', 'value' => number_format($stats['comments']), 'icon' => 'bi-chat-dots', 'color' => '#fbbf24'],
                ['label' => 'Nguồn tin', 'value' => number_format($stats['sources']), 'icon' => 'bi-rss', 'color' => 'var(--accent)'],
            ] as $stat)
            <div class="col-sm-6 col-lg-3">
                <div class="p-3 h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center gap-3">
                        <div style="background:rgba(96,165,250,.1);border-radius:10px;width:48px;height:48px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="bi {{ $stat['icon'] }}" style="font-size:1.4rem;color:{{ $stat['color'] }}"></i>
                        </div>
                        <div>
                            <div style="font-size:1.5rem;font-weight:700;font-family:'Playfair Display',serif;color:var(--text)">
                                {{ $stat['value'] }}
                            </div>
                            <div style="font-size:.8rem;color:var(--text-muted)">{{ $stat['label'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="row g-4">
            {{-- Recent Articles --}}
            <div class="col-lg-7">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Bài viết mới nhất</div>
                    @foreach($recentArticles as $a)
                    <div class="d-flex align-items-start gap-2 py-2" style="border-bottom:1px solid var(--border)">
                        <div class="flex-fill">
                            <a href="{{ route('articles.show', $a->slug) }}"
                               style="font-size:.875rem;color:var(--text);text-decoration:none;font-weight:500">
                                {{ Str::limit($a->title, 60) }}
                            </a>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem">
                                {{ $a->source->name }} &middot; {{ $a->created_at->diffForHumans() }}
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Source Health --}}
            <div class="col-lg-5">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Trạng thái nguồn tin</div>
                    <div style="max-height:360px;overflow-y:auto">
                        @foreach($sources as $src)
                        <div class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px solid var(--border)">
                            <span class="d-inline-block rounded-circle"
                                  style="width:8px;height:8px;flex-shrink:0;background:{{ $src->is_active ? '#22c55e' : '#6b7280' }}"></span>
                            <div class="flex-fill" style="min-width:0">
                                <div style="font-size:.8rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    {{ $src->name }}
                                </div>
                                <div style="font-size:.7rem;color:var(--text-muted)">
                                    {{ $src->last_fetched_at ? $src->last_fetched_at->diffForHumans() : 'Chưa lấy' }}
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 p-3 text-center"
             style="background:var(--surface-alt);border:1px solid var(--border);border-radius:12px;color:var(--text-muted);font-size:.9rem">
            <i class="bi bi-tools me-2"></i>
            Giao diện quản trị đầy đủ (CRUD nguồn, bài viết, người dùng) sẽ được triển khai ở Phase 5.
        </div>
    </div>
</x-app-layout>
