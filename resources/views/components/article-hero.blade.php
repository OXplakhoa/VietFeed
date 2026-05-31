@props(['article'])

<a href="{{ route('articles.show', $article->slug) }}" class="article-hero d-block text-decoration-none">
    <div class="article-hero-img-wrap">
        @if($article->image_url)
            <img src="{{ $article->image_url }}" alt="{{ $article->title }}" class="hero-img" loading="lazy">
        @else
            <div class="article-hero-placeholder"><i class="bi bi-newspaper"></i></div>
        @endif
    </div>
    <div class="article-hero-body">
        <span class="category-badge mb-2 d-inline-block">{{ $article->category->name }}</span>
        <div class="article-hero-title">{{ $article->title }}</div>
        <div class="article-hero-meta mt-2">
            {{ $article->source->name }}
            @if($article->published_at)
                &middot; {{ $article->published_at->diffForHumans() }}
            @endif
            &middot; {{ $article->reading_time }} phút đọc
        </div>
    </div>
</a>
