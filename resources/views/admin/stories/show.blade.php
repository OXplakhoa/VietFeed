<x-app-layout>
    <x-slot name="title">{{ $story->title }} — Duyệt tóm tắt Story</x-slot>
    <main class="container-xl py-4">
        @include('admin.partials.nav')
        <a href="{{ route('admin.stories.index') }}">Danh sách Story</a>
        <h1 class="h3 serif mt-3">{{ $story->title }}</h1>
        <p>Phiên bản {{ $story->version ?? 0 }} · <a href="{{ route('stories.show', $story->id) }}">Xem Story công khai</a></p>
        @foreach(['success' => 'success', 'error' => 'danger'] as $key => $class)
            @if(session($key))<p class="alert alert-{{ $class }}" role="status">{{ session($key) }}</p>@endif
        @endforeach
        @if($errors->any())
            <ul class="alert alert-danger">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        <section class="mb-4" aria-labelledby="candidate-title">
            <h2 id="candidate-title" class="h4">Bản nháp AI</h2>
            <p>Trạng thái: {{ $stateLabel }}.
                @if($points || $state === 'ready') Đã qua kiểm tra cấu trúc và ID nguồn.
                @elseif($expired) Quá hạn xử lý. Có thể tạo lại.
                @elseif($state === 'failed') Tạo tóm tắt thất bại ({{ $brief['error_category'] ?? 'worker_failed' }}{{ isset($brief['validation_reason']) ? ' · '.$brief['validation_reason'] : '' }}).
                @else Chưa có kết quả kiểm tra.
                @endif
            </p>
            @if($points)
                <ol>
                    @foreach($points as $point)
                        <li>{{ $point['text'] }}
                            <ul>
                                @foreach($point['evidence'] as $citation)
                                    <li><a href="#article-{{ $citation['id'] }}">{{ $citation['title'] }}</a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
                <form method="POST" action="{{ route('admin.stories.accept', $story->id) }}" class="mb-3">
                    @csrf
                    <input type="hidden" name="candidate_id" value="{{ $candidate['_id'] }}">
                    <input type="hidden" name="story_version" value="{{ $candidate['story_version'] }}">
                    <label class="form-label" for="review-note">Ghi chú duyệt (không bắt buộc)</label>
                    <textarea id="review-note" name="note" class="form-control mb-2" rows="2" maxlength="500">{{ old('note') }}</textarea>
                    <button type="submit" class="btn btn-success">Duyệt</button>
                    <button type="submit" class="btn btn-outline-danger" formaction="{{ route('admin.stories.reject', $story->id) }}">Từ chối</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.stories.retry', $story->id) }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">Tạo lại</button>
            </form>
        </section>

        <section class="mb-4" aria-labelledby="evidence-title">
            <h2 id="evidence-title" class="h4">Bài viết từ các nguồn</h2>
            @forelse($evidence as $article)
                <article id="article-{{ $article['id'] }}" class="mb-3">
                    <h3 class="h6">{{ $article['title'] }}</h3>
                    <p class="mb-1">{{ $article['source']['name'] ?? 'Nguồn chưa xác định' }} · <time datetime="{{ $article['published_at'] }}">{{ $article['published_at'] }}</time></p>
                    <p>{{ $article['excerpt'] }}</p>
                </article>
            @empty
                <p>Chưa có bài viết cho sự kiện này.</p>
            @endforelse
        </section>

        <section class="table-responsive" aria-labelledby="history-title">
            <h2 id="history-title" class="h4">Lịch sử tóm tắt</h2>
            <table class="table align-middle">
                <caption>50 bản tóm tắt và lần tạo gần đây</caption>
                <thead><tr><th scope="col">Phiên bản Story</th><th scope="col">Loại</th><th scope="col">Mô hình</th><th scope="col">Trạng thái</th><th scope="col">Thời điểm tạo (UTC)</th><th scope="col">Duyệt</th></tr></thead>
                <tbody>
                    @forelse($history as $output)
                        <tr>
                            <td>{{ $output['story_version'] }}</td><td>{{ $output['kind'] }}</td>
                            <td>{{ $output['provider'] ?? '—' }} {{ $output['model'] }}</td>
                            <td>{{ $output['status'] }} {{ $output['error_category'] ?? '' }} {{ $output['validation_reason'] ?? '' }}</td>
                            <td>{{ $output['created_at']->toDateTimeImmutable()->format('d/m/Y H:i:s') }}</td>
                            <td>
                                @if($output['reviewed_at'])
                                    {{ $output['review_decision'] }} · {{ $output['reviewed_by'] }}
                                    · {{ $output['reviewed_at']->toDateTimeImmutable()->format('d/m/Y H:i:s') }} UTC
                                    @if($output['review_note'])<p class="mb-0">{{ $output['review_note'] }}</p>@endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Chưa có lịch sử tóm tắt.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </main>
</x-app-layout>
