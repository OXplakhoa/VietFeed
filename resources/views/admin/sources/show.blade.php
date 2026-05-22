<x-app-layout>
    <x-slot name="title">{{ $source->name }} — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.sources.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Nguồn tin
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.3rem">{{ $source->name }}</h3>
            <a href="{{ route('admin.sources.edit', $source) }}" class="btn btn-sm ms-auto"
               style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px;text-decoration:none">
                <i class="bi bi-pencil me-1"></i>Sửa
            </a>
        </div>

        {{-- Source Meta --}}
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="row g-2">
                        @foreach([
                            ['Chủ đề',      $source->category->name ?? '—'],
                            ['URL trang chủ',$source->url],
                            ['RSS Feed',     $source->feed_url],
                            ['Logo URL',     $source->logo_url ?? '—'],
                            ['Trạng thái',   $source->is_active ? 'Hoạt động' : 'Tắt'],
                            ['Cập nhật lần cuối', $source->last_fetched_at ? $source->last_fetched_at->format('d/m/Y H:i') : 'Chưa lấy'],
                        ] as [$label, $val])
                        <div class="col-sm-6">
                            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.15rem">{{ $label }}</div>
                            <div style="font-size:.875rem;color:var(--text);word-break:break-all">{{ $val }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 text-center h-100" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <div style="font-size:2.5rem;font-weight:700;font-family:'Playfair Display',serif;color:var(--accent)">
                        {{ number_format($articles->total()) }}
                    </div>
                    <div style="font-size:.85rem;color:var(--text-muted)">Bài viết đã lấy</div>
                </div>
            </div>
        </div>

        {{-- Articles Table --}}
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
                            <a href="{{ route('articles.show', $a->slug) }}" target="_blank"
                               style="color:var(--text);text-decoration:none">
                                {{ Str::limit($a->title, 70) }}
                            </a>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap">
                            <i class="bi bi-bookmark me-1"></i>{{ $a->bookmarks_count }}
                        </td>
                        <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap">
                            {{ $a->published_at?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="pe-3" style="white-space:nowrap">
                            <a href="{{ route('admin.articles.edit', $a) }}" style="font-size:.75rem;color:#60a5fa;text-decoration:none">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
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
