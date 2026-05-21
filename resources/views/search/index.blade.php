<x-app-layout>
    <x-slot name="title">{{ $q ? 'Tìm kiếm: '.$q : 'Tìm kiếm' }} — VietFeed</x-slot>

    <div class="container-xl py-4">
        {{-- Search Form --}}
        <div class="mb-4">
            <form action="{{ route('search') }}" method="GET" class="vf-search-form d-flex"
                  style="max-width:600px" autocomplete="off">
                <input type="text" name="q" class="form-control"
                       placeholder="Tìm kiếm bài viết…"
                       value="{{ $q }}" autofocus>
                <button type="submit" class="btn px-4">
                    <i class="bi bi-search"></i>
                </button>
            </form>
        </div>

        @if($q)
        <div class="section-header">
            <h2>Kết quả cho "{{ $q }}"</h2>
            @if($articles instanceof \Illuminate\Pagination\LengthAwarePaginator)
            <span style="font-size:.85rem;color:var(--text-muted);margin-left:auto">
                {{ $articles->total() }} bài viết
            </span>
            @endif
        </div>

        @if($articles->isEmpty())
        <div class="text-center py-5" style="color:var(--text-muted)">
            <i class="bi bi-search" style="font-size:2.5rem;display:block;margin-bottom:1rem"></i>
            <p>Không tìm thấy kết quả nào cho "<strong>{{ $q }}</strong>".</p>
            <a href="{{ route('home') }}" style="color:var(--accent)">← Quay về trang chủ</a>
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

        @else
        <div class="text-center py-5" style="color:var(--text-muted)">
            <i class="bi bi-search" style="font-size:2.5rem;display:block;margin-bottom:1rem"></i>
            <p>Nhập từ khoá để tìm kiếm bài viết.</p>
        </div>
        @endif
    </div>
</x-app-layout>
