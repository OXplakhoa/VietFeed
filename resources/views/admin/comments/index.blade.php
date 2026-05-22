<x-app-layout>
    <x-slot name="title">Bình luận — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-chat-dots me-2" style="color:var(--accent)"></i>Kiểm duyệt bình luận
                <span style="font-size:.9rem;font-weight:400;color:var(--text-muted)">({{ number_format($comments->total()) }})</span>
            </h3>
        </div>

        {{-- Search --}}
        <form method="GET" action="{{ route('admin.comments.index') }}" class="mb-4">
            <div class="d-flex gap-2">
                <input type="text" name="q" value="{{ request('q') }}"
                       class="form-control form-control-sm"
                       placeholder="Tìm trong nội dung bình luận…"
                       style="background:var(--surface);border-color:var(--border);color:var(--text);max-width:360px">
                <button type="submit" class="btn btn-sm px-3"
                        style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                    <i class="bi bi-search"></i>
                </button>
                @if(request('q'))
                <a href="{{ route('admin.comments.index') }}" class="btn btn-sm"
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
                            <th>Bài viết</th>
                            <th>Nội dung</th>
                            <th>Thời gian</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($comments as $c)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle;white-space:nowrap">
                            <div style="font-size:.85rem;font-weight:500;color:var(--text)">{{ $c->user?->name ?? 'Ẩn danh' }}</div>
                            <div style="font-size:.75rem;color:var(--text-muted)">{{ $c->user?->email }}</div>
                        </td>
                        <td style="vertical-align:middle;max-width:200px">
                            <a href="{{ $c->article ? route('articles.show', $c->article->slug) : '#' }}"
                               target="_blank" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                {{ $c->article?->title ?? '—' }}
                            </a>
                        </td>
                        <td style="vertical-align:middle;max-width:300px">
                            <div style="font-size:.85rem;color:var(--text);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
                                @if($c->parent_id)
                                <span style="font-size:.72rem;color:var(--accent);margin-right:.3rem">↳ Trả lời</span>
                                @endif
                                {{ $c->body }}
                            </div>
                        </td>
                        <td style="vertical-align:middle;font-size:.78rem;color:var(--text-muted);white-space:nowrap">
                            {{ $c->created_at->diffForHumans() }}
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle">
                            <form action="{{ route('admin.comments.destroy', $c) }}" method="POST"
                                  onsubmit="vfConfirmForm(event, this, 'Bình luận này sẽ bị xóa vĩnh viễn.')">
                                @csrf @method('DELETE')
                                <button type="submit" style="color:#ef4444;font-size:.8rem;background:none;border:none;padding:0;cursor:pointer">
                                    <i class="bi bi-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center py-4" style="color:var(--text-muted)">
                            @if(request('q')) Không tìm thấy bình luận nào. @else Chưa có bình luận nào. @endif
                        </td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $comments->links() }}</div>
    </div>
</x-app-layout>
