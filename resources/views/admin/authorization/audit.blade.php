<x-app-layout>
    <x-slot name="title">Nhật ký phân quyền — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')
        <h3 class="mb-3">Nhật ký phân quyền</h3>

        @if($events === null)
            <p role="status">Nhật ký phân quyền hiện không khả dụng.</p>
        @elseif($events === [])
            <p>Chưa có sự kiện phân quyền trong 30 ngày qua.</p>
        @else
            <div class="table-responsive">
                <table class="table" style="color:var(--text)">
                    <thead><tr>
                        <th scope="col">Thời điểm (UTC)</th>
                        <th scope="col">Người thực hiện</th>
                        <th scope="col">Tài khoản</th>
                        <th scope="col">Trước</th>
                        <th scope="col">Sau</th>
                        <th scope="col">Lý do</th>
                    </tr></thead>
                    <tbody>
                        @foreach($events as $event)
                            <tr>
                                <td>{{ $event['occurred_at'] }}</td>
                                <td>{{ $event['actor_id'] }}</td>
                                <td>{{ $event['target_id'] }}</td>
                                <td><code>{{ $event['before_state'] }}</code></td>
                                <td><code>{{ $event['after_state'] }}</code></td>
                                <td>{{ $event['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
