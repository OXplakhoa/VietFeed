<x-app-layout>
    <x-slot name="title">{{ $lens['story']['title'] }} — VietFeed</x-slot>
    <main id="story-lens" class="container-xl py-4" data-poll-url="{{ route('stories.sections', $lens['story']['id']) }}">
        <h1 class="serif mb-4">{{ $lens['story']['title'] }}</h1>
        <section id="story-brief" data-state="{{ $lens['brief']['state'] }}" aria-labelledby="brief-title" class="mb-4">
            <h2 id="brief-title" class="h4">{{ $lens['brief']['kind'] === 'none' ? 'Tóm tắt' : $lens['brief']['label'] }}</h2>
            @if($lens['brief']['kind'] === 'none')
                <p>{{ $lens['brief']['label'] }}</p>
            @else
                <ul>
                    @foreach($lens['brief']['points'] as $point)
                        <li>{{ $point['text'] }}
                            @foreach($point['evidence'] as $citation)
                                <a href="#article-{{ $citation['id'] }}">{{ $citation['title'] }}</a>@if(!$loop->last), @endif
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section id="story-evidence" aria-labelledby="evidence-title" class="mb-4">
            <h2 id="evidence-title" class="h4">Bài viết từ các nguồn</h2>
            @forelse($lens['evidence'] as $article)
                <article id="article-{{ $article['id'] }}" class="p-3 mb-3 rounded" style="background:var(--surface);border:1px solid var(--border)">
                    <h3 class="h5">@if($article['original_url'])<a href="{{ $article['original_url'] }}" rel="noopener noreferrer">{{ $article['title'] }}</a>@else{{ $article['title'] }}@endif</h3>
                    <p style="color:var(--text-muted)">{{ $article['source']['name'] ?? 'Nguồn chưa xác định' }} · <time datetime="{{ $article['published_at'] }}">{{ $article['published_at_display'] }}</time></p>
                    <p class="mb-0">{{ $article['excerpt'] }}</p>
                </article>
            @empty
                <p>Chưa có bài viết cho sự kiện này.</p>
            @endforelse
        </section>
        @foreach(['timeline' => 'Diễn biến sự kiện', 'graph' => 'Các nguồn đưa tin'] as $name => $title)
            @php $section = $lens['sections'][$name]; @endphp
            <section data-section="{{ $name }}" data-state="{{ $section['state'] }}" class="p-3 mb-4 rounded" style="background:var(--surface);border:1px solid var(--border)" aria-labelledby="{{ $name }}-title">
                <h2 id="{{ $name }}-title" class="h4">{{ $title }}</h2>
                <p data-section-status role="status" aria-live="polite">{{ ['pending' => 'Đang xử lý', 'ready' => 'Sẵn sàng', 'failed' => 'Tạm thời chưa thể tải mục này. Hệ thống sẽ thử lại.'][$section['state']] }} ({{ $section['state'] }})</p>
                <ul data-section-data>
                    @if($section['state'] === 'ready')
                        @forelse($section['data'] as $row)
                            <li>
                                @if($name === 'timeline')
                                    <time datetime="{{ $row['occurred_at'] }}">{{ $row['occurred_at_display'] }}</time> · Bổ sung bài viết · {{ $row['article_title'] }}
                                @else
                                    {{ $row['name'] }} · {{ $row['article_count'] }} bài viết
                                @endif
                            </li>
                        @empty
                            <li>Chưa có dữ liệu.</li>
                        @endforelse
                    @endif
                </ul>
            </section>
        @endforeach
        <noscript><p>Bật JavaScript hoặc tải lại trang để xem trạng thái mới nhất.</p></noscript>
    </main>
</x-app-layout>
