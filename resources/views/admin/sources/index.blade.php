<x-app-layout>
    <x-slot name="title">Nguồn tin — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-rss me-2" style="color:var(--accent)"></i>Nguồn tin
            </h3>
            <a href="{{ route('admin.sources.create') }}" class="btn-accent" style="text-decoration:none;padding:.45rem 1rem;border-radius:8px;font-size:.875rem">
                <i class="bi bi-plus-lg me-1"></i>Thêm nguồn
            </a>
        </div>

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color:var(--text)">
                    <thead style="background:var(--surface-alt);font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                        <tr>
                            <th class="ps-3 py-3">Tên nguồn</th>
                            <th>Chủ đề</th>
                            <th>Bài viết</th>
                            <th>Trạng thái</th>
                            <th>Cập nhật lần cuối</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sources as $src)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle">
                            <div style="font-weight:500;font-size:.875rem">
                                <a href="{{ route('admin.sources.show', $src) }}"
                                   style="color:var(--text);text-decoration:none">
                                    {{ $src->name }}
                                </a>
                            </div>
                            <div style="font-size:.75rem;color:var(--text-muted)">
                                <a href="{{ $src->feed_url }}" target="_blank" style="color:var(--text-muted)">
                                    {{ Str::limit($src->feed_url, 50) }}
                                </a>
                            </div>
                        </td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ $src->category->name ?? '—' }}</td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ number_format($src->articles_count) }}</td>
                        <td style="vertical-align:middle">
                            @if($src->is_active)
                                <span style="font-size:.75rem;background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);border-radius:6px;padding:.2rem .55rem;font-weight:500">Hoạt động</span>
                            @else
                                <span style="font-size:.75rem;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3);border-radius:6px;padding:.2rem .55rem;font-weight:500">Tắt</span>
                            @endif
                        </td>
                        <td style="vertical-align:middle;font-size:.8rem;color:var(--text-muted)">
                            {{ $src->last_fetched_at ? $src->last_fetched_at->diffForHumans() : 'Chưa lấy' }}
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle;white-space:nowrap">
                            <a href="{{ route('admin.sources.edit', $src) }}"
                               style="color:#60a5fa;font-size:.8rem;text-decoration:none;margin-right:.75rem">
                                <i class="bi bi-pencil me-1"></i>Sửa
                            </a>
                            <form action="{{ route('admin.sources.destroy', $src) }}" method="POST" class="d-inline"
                                  onsubmit="vfConfirmForm(event, this, 'Nguồn tin sẽ bị xóa. Các bài viết đã lấy sẽ không bị xóa.')">
                                @csrf @method('DELETE')
                                <button type="submit" style="color:#ef4444;font-size:.8rem;background:none;border:none;padding:0;cursor:pointer">
                                    <i class="bi bi-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-4" style="color:var(--text-muted)">Chưa có nguồn tin nào.</td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $sources->links() }}
        </div>
    </div>
</x-app-layout>
