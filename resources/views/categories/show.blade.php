<x-app-layout>
    <x-slot name="title">{{ $category->name }} — VietFeed</x-slot>

    <div class="container-xl py-4">
        <div class="section-header">
            <h2>{{ $category->name }}</h2>
            <span style="font-size:.85rem;color:var(--text-muted);margin-left:auto">
                {{ $articles->total() }} bài viết
            </span>
        </div>

        @if($articles->isEmpty())
        <div class="text-center py-5" style="color:var(--text-muted)">
            <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:1rem"></i>
            <p>Chưa có bài viết trong chủ đề này.</p>
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
