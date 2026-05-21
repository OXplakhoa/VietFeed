<x-app-layout>
    <x-slot name="title">Tất cả bài viết — VietFeed</x-slot>

    <div class="container-xl py-4">
        <div class="section-header mb-3">
            <h2>Tất cả bài viết</h2>
        </div>

        {{-- Filter Bar --}}
        <form method="GET" action="{{ route('articles.index') }}" class="filter-bar">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="Tìm kiếm…" value="{{ request('q') }}">
                </div>
                <div class="col-sm-3">
                    <select name="category" class="form-select form-select-sm">
                        <option value="">Tất cả chủ đề</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->slug }}" {{ request('category') === $cat->slug ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3">
                    <select name="source" class="form-select form-select-sm">
                        <option value="">Tất cả nguồn</option>
                        @foreach($sources as $src)
                        <option value="{{ $src->id }}" {{ request('source') == $src->id ? 'selected' : '' }}>
                            {{ $src->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-2">
                    <button type="submit" class="btn-accent w-100" style="justify-content:center">
                        <i class="bi bi-search"></i> Lọc
                    </button>
                </div>
            </div>
        </form>

        @if($articles->isEmpty())
        <div class="text-center py-5" style="color:var(--text-muted)">
            <i class="bi bi-search" style="font-size:2.5rem;display:block;margin-bottom:1rem"></i>
            <p>Không tìm thấy bài viết nào.</p>
            <a href="{{ route('articles.index') }}" style="color:var(--accent)">Xem tất cả bài viết</a>
        </div>
        @else
        <div class="row g-3">
            @foreach($articles as $article)
            <div class="col-sm-6 col-lg-4 mb-1 fade-in">
                <x-article-card
                    :article="$article"
                    :bookmarked="in_array($article->id, $bookmarkedIds)" />
            </div>
            @endforeach
        </div>
        <div class="d-flex justify-content-center mt-4">
            {{ $articles->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
