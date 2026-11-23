<x-app-layout>
    <x-slot name="title">Duyệt tóm tắt Story — Quản trị VietFeed</x-slot>
    <main class="container-xl py-4">
        @include('admin.partials.nav')
        <h1 class="h3 serif mb-4">Duyệt tóm tắt Story</h1>
        <div class="table-responsive">
            <table class="table align-middle">
                <caption>50 Story mới nhất</caption>
                <thead><tr><th scope="col">Story</th><th scope="col">Phiên bản</th><th scope="col">Bài viết</th><th scope="col">Trạng thái</th><th scope="col">Đang hiển thị</th></tr></thead>
                <tbody>
                    @forelse($stories as $story)
                        <tr>
                            <th scope="row"><a href="{{ route('admin.stories.show', $story['id']) }}">{{ $story['title'] }}</a></th>
                            <td>{{ $story['version'] }}</td><td>{{ $story['articles'] }}</td>
                            <td>{{ $story['state_label'] }}</td><td>{{ $story['published'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Chưa có Story.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</x-app-layout>
