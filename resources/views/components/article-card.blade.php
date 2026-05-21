@props(['article', 'bookmarked' => false])

<div class="article-card">
    <a href="{{ route('articles.show', $article->slug) }}" class="text-decoration-none">
        <div class="article-card-img">
            @if($article->image_url)
                <img src="{{ $article->image_url }}" alt="{{ $article->title }}" loading="lazy">
            @else
                <div class="article-card-img-placeholder">
                    <i class="bi bi-newspaper"></i>
                </div>
            @endif
        </div>
    </a>
    <div class="article-card-body">
        <a href="{{ route('categories.show', $article->category->slug) }}" class="category-badge">
            {{ $article->category->name }}
        </a>
        <h3 class="article-card-title">
            <a href="{{ route('articles.show', $article->slug) }}">{{ $article->title }}</a>
        </h3>
        <div class="article-card-meta">
            <span>{{ $article->source->name }}</span>
            @if($article->published_at)
                <span class="sep">{{ $article->published_at->diffForHumans() }}</span>
            @endif
            <span class="sep">{{ $article->reading_time }} phút</span>
            @auth
            <button class="bookmark-btn ms-auto {{ $bookmarked ? 'active' : '' }}"
                    data-article-id="{{ $article->id }}"
                    title="{{ $bookmarked ? 'Bỏ lưu' : 'Lưu bài viết' }}">
                <i class="bi {{ $bookmarked ? 'bi-bookmark-fill' : 'bi-bookmark' }}"></i>
            </button>
            @endauth
        </div>
    </div>
</div>
