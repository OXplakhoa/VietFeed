@props(['article'])

<a href="{{ route('articles.show', $article->slug) }}" class="article-hero d-block text-decoration-none">
    @if($article->image_url)
        <img src="{{ $article->image_url }}" alt="{{ $article->title }}" class="hero-img">
    @else
        <div class="article-hero-placeholder"><i class="bi bi-newspaper"></i></div>
    @endif
    <div class="article-hero-overlay">
        <a href="{{ route('categories.show', $article->category->slug) }}" class="category-badge mb-2 d-inline-block">
            {{ $article->category->name }}
        </a>
        <div class="article-hero-title">
            {{ $article->title }}
        </div>
        <div class="article-hero-meta mt-2">
            {{ $article->source->name }}
            @if($article->published_at)
                &middot; {{ $article->published_at->diffForHumans() }}
            @endif
            &middot; {{ $article->reading_time }} phút đọc
        </div>
    </div>
</a>
