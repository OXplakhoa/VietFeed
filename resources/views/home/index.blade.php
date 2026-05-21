<x-app-layout>
    <x-slot name="title">Trang chủ — VietFeed</x-slot>

    {{-- ── Category Tabs ─────────────────────────────────────────────── --}}
    <div class="category-tabs-bar">
        <div class="container-xl">
            <nav class="nav flex-nowrap">
                <a href="{{ route('home') }}"
                   class="nav-link {{ request()->routeIs('home') && !request('cat') ? 'active' : '' }}">
                    Tất cả
                </a>
                @foreach($categories as $cat)
                <a href="{{ route('categories.show', $cat->slug) }}"
                   class="nav-link {{ request()->is('categories/'.$cat->slug) ? 'active' : '' }}">
                    {{ $cat->name }}
                </a>
                @endforeach
            </nav>
        </div>
    </div>

    <div class="container-xl py-4">

        {{-- ── Hero Grid ──────────────────────────────────────────────── --}}
        @if($featured)
        <div class="hero-grid mb-4">
            {{-- Large featured article --}}
            <x-article-hero :article="$featured" />

            {{-- 3 small articles stacked --}}
            <div class="hero-stack">
                @foreach($heroArticles as $h)
                <a href="{{ route('articles.show', $h->slug) }}" class="article-hero-sm">
                    <div class="article-hero-sm-img">
                        @if($h->image_url)
                            <img src="{{ $h->image_url }}" alt="{{ $h->title }}" loading="lazy">
                        @else
                            <div class="article-hero-sm-placeholder"><i class="bi bi-newspaper"></i></div>
                        @endif
                    </div>
                    <div class="article-hero-sm-body">
                        <div class="category-badge mb-1">{{ $h->category->name }}</div>
                        <div class="article-hero-sm-title">{{ $h->title }}</div>
                        <div class="article-hero-sm-meta">
                            {{ $h->source->name }}
                            @if($h->published_at) &middot; {{ $h->published_at->diffForHumans() }} @endif
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- ── Main Feed + Sidebar ─────────────────────────────────────── --}}
        <div class="row g-4">
            {{-- Article Grid --}}
            <div class="col-lg-8">
                <div class="section-header">
                    <h2>
                        @auth
                            @if(auth()->user()->favoriteCategories()->count() > 0)
                                Dành cho bạn
                            @else
                                Tin mới nhất
                            @endif
                        @else
                            Tin mới nhất
                        @endauth
                    </h2>
                    @auth
                    @if(auth()->user()->favoriteCategories()->count() === 0)
                    <a href="{{ route('onboarding.interests') }}"
                       style="font-size:.8rem;color:var(--accent);text-decoration:none;margin-left:auto">
                        <i class="bi bi-sliders me-1"></i>Tuỳ chỉnh feed
                    </a>
                    @endif
                    @endauth
                </div>

                <div class="row g-3" id="articles-grid">
                    @foreach($articles as $article)
                    <div class="col-sm-6 col-lg-4 mb-1 fade-in">
                        <x-article-card
                            :article="$article"
                            :bookmarked="in_array($article->id, $bookmarkedIds)" />
                    </div>
                    @endforeach
                </div>

                {{-- Infinite scroll anchor --}}
                <div id="infinite-scroll-anchor"
                     data-has-more="{{ $articles->hasMorePages() ? 'true' : 'false' }}"
                     data-next-page="{{ $articles->currentPage() + 1 }}"
                     style="height:20px">
                </div>

                @if($articles->isEmpty())
                <div class="text-center py-5" style="color:var(--text-muted)">
                    <i class="bi bi-newspaper" style="font-size:2.5rem;display:block;margin-bottom:1rem"></i>
                    <p>Chưa có bài viết nào. <a href="{{ route('onboarding.interests') }}" style="color:var(--accent)">Chọn chủ đề yêu thích</a>.</p>
                </div>
                @endif
            </div>

            {{-- Trending Sidebar --}}
            <div class="col-lg-4">
                <div class="trending-sidebar">
                    <div class="sidebar-title">
                        <i class="bi bi-fire" style="color:var(--accent)"></i> Đang thịnh hành
                    </div>
                    @foreach($trending as $i => $t)
                    <a href="{{ route('articles.show', $t->slug) }}" class="trending-item">
                        <span class="trending-num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        <div>
                            <div class="trending-item-title">{{ $t->title }}</div>
                            <div style="font-size:.73rem;color:var(--text-muted);margin-top:.2rem">
                                {{ $t->bookmarks_count }} lượt lưu
                            </div>
                        </div>
                    </a>
                    @endforeach
                </div>

                {{-- Quick category links --}}
                <div class="trending-sidebar mt-3">
                    <div class="sidebar-title">Chủ đề</div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($categories as $cat)
                        <a href="{{ route('categories.show', $cat->slug) }}"
                           class="badge text-decoration-none"
                           style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);padding:.45rem .85rem;border-radius:6px;font-size:.82rem;transition:border-color .2s,color .2s"
                           onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'"
                           onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-muted)'">
                            {{ $cat->name }}
                        </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
