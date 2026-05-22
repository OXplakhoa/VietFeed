<x-app-layout>
    <x-slot name="title">{{ Str::limit($article->title, 40) }} — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.articles.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Bài viết
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.2rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px">
                {{ $article->title }}
            </h3>
            <div class="ms-auto d-flex gap-2">
                <a href="{{ route('articles.show', $article->slug) }}" target="_blank"
                   class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px;text-decoration:none;font-size:.8rem">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Xem bài
                </a>
                <a href="{{ route('admin.articles.edit', $article) }}"
                   class="btn btn-sm" style="background:var(--accent);color:#fff;border:none;border-radius:8px;text-decoration:none;font-size:.8rem">
                    <i class="bi bi-pencil me-1"></i>Sửa
                </a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                {{-- Article Content --}}
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    @if($article->image_url)
                    <img src="{{ $article->image_url }}" alt="{{ $article->title }}"
                         style="width:100%;height:220px;object-fit:cover;border-radius:8px;margin-bottom:1rem">
                    @endif
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--text);margin-bottom:.75rem">{{ $article->title }}</h1>
                    <div style="font-size:.85rem;color:var(--text);line-height:1.7">{{ $article->description }}</div>
                </div>

                {{-- Comments --}}
                <div class="mt-4 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">
                        Bình luận ({{ $article->comments->count() }})
                    </div>
                    @forelse($article->comments as $c)
                    <div class="d-flex justify-content-between align-items-start py-2" style="border-bottom:1px solid var(--border)">
                        <div>
                            <div style="font-size:.8rem;font-weight:500;color:var(--text)">{{ $c->user?->name ?? 'Ẩn danh' }}</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">{{ $c->body }}</div>
                            <div style="font-size:.7rem;color:var(--text-muted)">{{ $c->created_at->diffForHumans() }}</div>
                        </div>
                        <form action="{{ route('admin.comments.destroy', $c) }}" method="POST"
                              onsubmit="return confirm('Xóa bình luận?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="color:#ef4444;background:none;border:none;font-size:.75rem;cursor:pointer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    @empty
                    <p style="color:var(--text-muted);font-size:.85rem">Chưa có bình luận.</p>
                    @endforelse
                </div>
            </div>

            <div class="col-lg-4">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Chi tiết</div>
                    @foreach([
                        ['Chủ đề',      $article->category->name],
                        ['Nguồn',        $article->source->name],
                        ['Slug',         $article->slug],
                        ['Ngày đăng',   $article->published_at?->format('d/m/Y H:i') ?? '—'],
                        ['Tạo lúc',     $article->created_at->format('d/m/Y H:i')],
                        ['Đã lưu',       $article->comments->count() . ' bình luận'],
                    ] as [$k, $v])
                    <div class="mb-2">
                        <div style="font-size:.72rem;color:var(--text-muted)">{{ $k }}</div>
                        <div style="font-size:.8rem;color:var(--text)">{{ $v }}</div>
                    </div>
                    @endforeach

                    <div class="mb-2">
                        <div style="font-size:.72rem;color:var(--text-muted)">URL gốc</div>
                        <a href="{{ $article->original_url }}" target="_blank"
                           style="font-size:.8rem;color:var(--accent);word-break:break-all">
                            {{ Str::limit($article->original_url, 45) }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
