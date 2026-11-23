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
                        <th scope="col">Thay đổi trước → sau</th>
                        <th scope="col">Lý do</th>
                    </tr></thead>
                    <tbody>
                        @foreach($events as $event)
                            <tr>
                                <td>{{ $event['occurred_at'] }}</td>
                                @foreach([$event['actor_id'], $event['target_id']] as $id)
                                    <td title="{{ $id }}">{{ $people[$id] }}</td>
                                @endforeach
                                <td>
                                    @if($event['changes'])
                                        <ul class="list-unstyled mb-0">
                                            @foreach($event['changes'] as $change)
                                                <li>{{ $change }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        Không thay đổi
                                    @endif
                                </td>
                                <td>{{ $event['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
