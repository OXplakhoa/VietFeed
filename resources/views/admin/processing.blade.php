<x-app-layout>
    <x-slot name="title">Xử lý Story — Quản trị VietFeed</x-slot>
    <div class="container-xl py-4">
        @include('admin.partials.nav')
        <h1 class="h3 serif">Xử lý Story</h1>
        <p style="color:var(--text-muted)">Thử lại chỉ đưa yêu cầu vào hàng đợi; bộ xử lý thực hiện công việc sau đó. Tải lại trang để xem trạng thái mới.</p>
        @if($unavailable)<p role="alert">Tạm thời không thể tải tình trạng xử lý.</p>@endif
        @foreach($overview['sections'] as $name => $section)
            <section class="p-3 mb-4 rounded" style="background:var(--surface);border:1px solid var(--border)">
                <h2 class="h4">{{ $name }}</h2>
                <p>Stream: {{ $section['lengths']['stream'] }} · Pending: {{ $section['lengths']['pending'] }} · DLQ: {{ $section['lengths']['dead_letters'] }}</p>
                <p style="color:var(--text-muted)">Hiển thị tối đa 1.000 pending và 100 DLQ đầu tiên. Stream dùng chung; mỗi mục có nhóm xử lý riêng.</p>
                <h3 class="h5">Đang chờ xác nhận</h3>
                <p>Pending chỉ ghi mã giao, bộ xử lý, thời gian chờ và số lần giao; không có Story, loại sự kiện, phiên bản hoặc lỗi cuối trong bản liệt kê này.</p>
                <div class="table-responsive"><table class="table" style="color:var(--text)">
                    <thead><tr><th>Mã giao</th><th>Bộ xử lý</th><th>Chờ (ms)</th><th>Số lần giao</th></tr></thead>
                    <tbody>@forelse($section['pending'] as $entry)<tr>@foreach($entry as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="4">Không có yêu cầu đang chờ.</td></tr>@endforelse</tbody>
                </table></div>
                <h3 class="h5">Dead letter</h3>
                <div class="table-responsive"><table class="table" style="color:var(--text)">
                    <thead><tr><th>Mã</th><th>Story</th><th>Sự kiện</th><th>Phiên bản</th><th>Số lần thử</th><th>Lỗi cuối</th><th>Thao tác</th></tr></thead>
                    <tbody>@forelse($section['dead'] as $entry)
                        <tr><td>{{ $entry['id'] }}</td><td>@if($entry['story'] && \Illuminate\Support\Str::isUuid($entry['story']))<a href="{{ route('stories.show', $entry['story']) }}">{{ $entry['story_title'] }} · {{ $entry['story'] }}</a>@else Không xác định @endif</td>
                        <td>{{ $entry['type'] }}</td><td>{{ $entry['version'] }}</td><td>{{ $entry['attempts'] ?? 'Không ghi nhận' }}</td>
                        <td>{{ $entry['has_error'] ? 'Lỗi xử lý đã được ghi nhận.' : 'Không ghi nhận' }}</td>
                        <td><form method="POST" action="{{ route('admin.processing.retry') }}">@csrf
                            <input type="hidden" name="section" value="{{ $name }}"><input type="hidden" name="id" value="{{ $entry['id'] }}">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Thử lại</button>
                        </form></td></tr>
                    @empty<tr><td colspan="7">Không có dead letter.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
        @endforeach
        {{-- Gate 9b: moderation audit (Mongo outbox -> Redis Stream -> Cassandra moderation_events_by_case) --}}
        <section class="p-3 mb-4 rounded" id="moderation-audit" style="background:var(--surface);border:1px solid var(--border)">
            <h2 class="h4">Kiểm duyệt · lịch sử quyết định</h2>
            @if($moderation === null)
                <p role="alert">Tạm thời không thể tải tình trạng luồng kiểm duyệt.</p>
            @else
                <p>Stream: {{ $moderation['lengths']['stream'] }} · Pending: {{ $moderation['lengths']['pending'] }} · DLQ: {{ $moderation['lengths']['dead_letters'] }}</p>
                <p style="color:var(--text-muted)">Sự kiện gốc nằm trong MongoDB; nếu Cassandra ngừng hoạt động, sự kiện chuyển vào DLQ sau 5 lần thử và có thể đưa lại vào hàng đợi tại đây.</p>
                <div class="table-responsive"><table class="table" style="color:var(--text)">
                    <thead><tr><th>Mã</th><th>Vụ việc</th><th>Sự kiện</th><th>Phiên bản</th><th>Số lần thử</th><th>Lỗi cuối</th><th>Thao tác</th></tr></thead>
                    <tbody>@forelse($moderation['dead'] as $entry)
                        <tr><td>{{ $entry['id'] }}</td><td>{{ $entry['case'] ?? 'Không xác định' }}</td><td>{{ $entry['type'] }}</td><td>{{ $entry['version'] }}</td>
                        <td>{{ $entry['attempts'] ?? 'Không ghi nhận' }}</td><td>{{ $entry['has_error'] ? 'Lỗi xử lý đã được ghi nhận.' : 'Không ghi nhận' }}</td>
                        <td><form method="POST" action="{{ route('admin.processing.retry') }}">@csrf
                            <input type="hidden" name="section" value="moderation"><input type="hidden" name="id" value="{{ $entry['id'] }}">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Thử lại</button>
                        </form></td></tr>
                    @empty<tr><td colspan="7">Không có dead letter.</td></tr>@endforelse</tbody>
                </table></div>
            @endif
        </section>
        <h2 class="h4">Trạng thái Story</h2>
        <p>Hiển thị tối đa {{ $overview['story_limit'] ?? 50 }} Story mới nhất.</p>
        <div class="table-responsive"><table class="table" style="color:var(--text)">
            <thead><tr><th>Story</th><th>Phiên bản</th><th>Timeline</th><th>Graph</th></tr></thead>
            <tbody>@forelse($overview['stories'] as $story)<tr><td><a href="{{ route('stories.show', $story['id']) }}">{{ $story['title'] }} · {{ $story['id'] }}</a></td><td>{{ $story['version'] }}</td><td>{{ $story['timeline'] }}</td><td>{{ $story['graph'] }}</td></tr>@empty<tr><td colspan="4">Chưa có Story.</td></tr>@endforelse</tbody>
        </table></div>
    </div>
</x-app-layout>
