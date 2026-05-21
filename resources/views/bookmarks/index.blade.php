<x-app-layout>
    <x-slot name="title">Bài đã lưu — VietFeed</x-slot>

    <div class="container-xl py-4">
        <div class="section-header">
            <h2>Bài viết đã lưu</h2>
            <span style="font-size:.85rem;color:var(--text-muted);margin-left:auto">
                {{ $articles->count() }} bài viết
            </span>
        </div>

        @if($articles->isEmpty())
        <div class="text-center py-5" style="color:var(--text-muted)">
            <i class="bi bi-bookmark" style="font-size:3rem;display:block;margin-bottom:1rem"></i>
            <p class="mb-3">Bạn chưa lưu bài viết nào.</p>
            <a href="{{ route('home') }}" class="btn-accent" style="text-decoration:none">
                <i class="bi bi-house me-1"></i>Khám phá tin tức
            </a>
        </div>
        @else
        <div class="row g-3">
            @foreach($articles as $article)
            <div class="col-sm-6 col-lg-4 mb-1 fade-in">
                <x-article-card :article="$article" :bookmarked="true" />
            </div>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>
