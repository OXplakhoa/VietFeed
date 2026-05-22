<div class="mb-4 p-2" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
    <div class="d-flex flex-wrap gap-1">
        @php
        $navItems = [
            ['route' => 'admin.dashboard',          'pattern' => 'admin.dashboard',      'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['route' => 'admin.sources.index',       'pattern' => 'admin.sources.*',       'icon' => 'bi-rss',          'label' => 'Nguồn tin'],
            ['route' => 'admin.categories.index',    'pattern' => 'admin.categories.*',    'icon' => 'bi-tag',          'label' => 'Chủ đề'],
            ['route' => 'admin.articles.index',      'pattern' => 'admin.articles.*',      'icon' => 'bi-newspaper',    'label' => 'Bài viết'],
            ['route' => 'admin.comments.index',      'pattern' => 'admin.comments.*',      'icon' => 'bi-chat-dots',    'label' => 'Bình luận'],
            ['route' => 'admin.users.index',         'pattern' => 'admin.users.*',         'icon' => 'bi-people',       'label' => 'Người dùng'],
        ];
        @endphp
        @foreach($navItems as $item)
            @php $active = request()->routeIs($item['pattern']); @endphp
            <a href="{{ route($item['route']) }}"
               class="btn btn-sm {{ $active ? '' : '' }}"
               style="{{ $active
                   ? 'background:var(--accent);color:#fff;border:1px solid var(--accent)'
                   : 'background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border)' }};border-radius:8px;text-decoration:none;padding:.35rem .75rem;font-size:.82rem;font-weight:500;transition:all .2s">
                <i class="bi {{ $item['icon'] }} me-1"></i>{{ $item['label'] }}
            </a>
        @endforeach
    </div>
</div>
