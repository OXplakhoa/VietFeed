<x-app-layout>
    <x-slot name="title">{{ $article->title }} — VietFeed</x-slot>

    <div class="container-xl py-4">
        <div class="row g-4">
            {{-- Article Main --}}
            <div class="col-lg-8">

                {{-- Breadcrumb --}}
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb" style="font-size:.8rem;background:none;padding:0;margin:0">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}" style="color:var(--text-muted)">Trang chủ</a></li>
                        <li class="breadcrumb-item">
                            <a href="{{ route('categories.show', $article->category->slug) }}" style="color:var(--text-muted)">
                                {{ $article->category->name }}
                            </a>
                        </li>
                        <li class="breadcrumb-item active" style="color:var(--text-muted)" aria-current="page">
                            Bài viết
                        </li>
                    </ol>
                </nav>

                {{-- Category + Title --}}
                <a href="{{ route('categories.show', $article->category->slug) }}" class="category-badge mb-2 d-inline-block">
                    {{ $article->category->name }}
                </a>
                <h1 class="serif mb-3" style="font-size:clamp(1.5rem,4vw,2.2rem);font-weight:700;line-height:1.3;color:var(--text)">
                    {{ $article->title }}
                </h1>

                {{-- Meta --}}
                <div class="d-flex flex-wrap align-items-center gap-3 mb-4"
                     style="font-size:.85rem;color:var(--text-muted)">
                    <span><i class="bi bi-newspaper me-1"></i>{{ $article->source->name }}</span>
                    @if($article->published_at)
                    <span><i class="bi bi-clock me-1"></i>{{ $article->published_at->format('d/m/Y H:i') }}</span>
                    @endif
                    <span><i class="bi bi-book me-1"></i>{{ $article->reading_time }} phút đọc</span>
                    <span><i class="bi bi-bookmark me-1"></i>{{ $article->bookmarks_count }} lượt lưu</span>
                </div>

                {{-- Hero Image --}}
                @if($article->image_url)
                <img src="{{ $article->image_url }}" alt="{{ $article->title }}"
                     class="article-detail-img mb-4">
                @endif

                {{-- Description --}}
                @if($article->description)
                <div class="article-detail-body mb-4">
                    {!! nl2br(e($article->description)) !!}
                </div>
                @endif

                {{-- CTA + Actions --}}
                <div class="d-flex flex-wrap align-items-center gap-3 mb-4 p-3"
                     style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <a href="{{ $article->original_url }}" target="_blank" rel="noopener"
                       class="btn-accent" style="text-decoration:none">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Đọc bài đầy đủ
                    </a>

                    @auth
                    <button class="bookmark-btn {{ $isBookmarked ? 'active' : '' }}"
                            data-article-id="{{ $article->id }}">
                        <i class="bi {{ $isBookmarked ? 'bi-bookmark-fill' : 'bi-bookmark' }}"></i>
                        <span>{{ $isBookmarked ? 'Đã lưu' : 'Lưu bài' }}</span>
                    </button>
                    @endauth

                    {{-- Share Buttons --}}
                    <div class="ms-auto d-flex gap-2">
                        <button onclick="copyLink()" class="bookmark-btn" title="Sao chép liên kết">
                            <i class="bi bi-link-45deg"></i>
                        </button>
                        <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(url()->current()) }}"
                           target="_blank" rel="noopener"
                           class="bookmark-btn" title="Chia sẻ Facebook">
                            <i class="bi bi-facebook"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?url={{ urlencode(url()->current()) }}&text={{ urlencode($article->title) }}"
                           target="_blank" rel="noopener"
                           class="bookmark-btn" title="Chia sẻ Twitter/X">
                            <i class="bi bi-twitter-x"></i>
                        </a>
                    </div>
                </div>

                {{-- Comments --}}
                <div class="mb-5">
                    <div class="section-header">
                        <h2>Bình luận ({{ $article->comments->count() }})</h2>
                    </div>

                    @auth
                    <div class="mb-4 p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <form action="{{ route('comments.store', $article->slug) }}" method="POST">
                            @csrf
                            <div class="mb-2" style="font-size:.85rem;color:var(--text-muted)">
                                Bình luận với tư cách <strong style="color:var(--text)">{{ auth()->user()->name }}</strong>
                            </div>
                            <textarea name="body" class="vf-textarea mb-2" rows="3"
                                      placeholder="Viết bình luận của bạn…" required>{{ old('body') }}</textarea>
                            <button type="submit" class="btn-accent">
                                <i class="bi bi-send me-1"></i>Đăng bình luận
                            </button>
                        </form>
                    </div>
                    @else
                    <div class="mb-4 p-3 text-center"
                         style="background:var(--surface);border:1px solid var(--border);border-radius:12px;color:var(--text-muted)">
                        <a href="{{ route('login') }}" style="color:var(--accent)">Đăng nhập</a> để bình luận.
                    </div>
                    @endauth

                    @forelse($article->comments as $comment)
                        <x-comment :comment="$comment" :article="$article" />
                    @empty
                    <div class="text-center py-4" style="color:var(--text-muted);font-size:.9rem">
                        Chưa có bình luận nào. Hãy là người đầu tiên!
                    </div>
                    @endforelse
                </div>
            </div>

            {{-- Related Articles Sidebar --}}
            <div class="col-lg-4">
                @if($related->count())
                <div class="trending-sidebar">
                    <div class="sidebar-title">Bài viết liên quan</div>
                    @foreach($related as $r)
                    <a href="{{ route('articles.show', $r->slug) }}"
                       class="d-flex gap-2 py-2 text-decoration-none"
                       style="border-bottom:1px solid var(--border);color:var(--text)">
                        @if($r->image_url)
                        <div style="width:64px;height:56px;flex-shrink:0;border-radius:6px;overflow:hidden">
                            <img src="{{ $r->image_url }}" alt="" style="width:100%;height:100%;object-fit:cover">
                        </div>
                        @endif
                        <div>
                            <div style="font-size:.85rem;font-weight:500;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                                {{ $r->title }}
                            </div>
                            @if($r->published_at)
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem">
                                {{ $r->published_at->diffForHumans() }}
                            </div>
                            @endif
                        </div>
                    </a>
                    @endforeach
                </div>
                @endif

                <div class="trending-sidebar mt-3">
                    <div class="sidebar-title">Nguồn bài viết</div>
                    <a href="{{ $article->source->url }}" target="_blank" rel="noopener"
                       style="color:var(--text);text-decoration:none;font-size:.875rem">
                        <i class="bi bi-newspaper me-2" style="color:var(--accent)"></i>
                        {{ $article->source->name }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function copyLink() {
            navigator.clipboard.writeText(window.location.href)
                .then(() => showToast('Đã sao chép liên kết!', 'success'))
                .catch(() => showToast('Không thể sao chép', 'error'));
        }
    </script>
    @endpush
</x-app-layout>
