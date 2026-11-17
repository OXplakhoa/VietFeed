{{-- Gate 9b: decision history of one case from the Cassandra audit projection (moderation_events_by_case). --}}
@php
    $eventLabels = [
        'REPORTED' => 'Báo cáo mới', 'CASE_DISMISSED' => 'Bác bỏ', 'CASE_FALSE_REPORT' => 'Báo cáo sai',
        'SANCTION_ISSUED' => 'Áp dụng biện pháp xử lý', 'COMMENT_HIDDEN' => 'Ẩn bình luận', 'COMMENT_UNHIDDEN' => 'Hiện lại bình luận',
        'APPEAL_SUBMITTED' => 'Gửi kháng cáo', 'APPEAL_APPROVED' => 'Chấp nhận kháng cáo', 'APPEAL_DENIED' => 'Từ chối kháng cáo',
        'SANCTION_REVOKED' => 'Thu hồi biện pháp xử lý',
    ];
@endphp
<div class="p-4 mt-4" id="moderation-timeline" data-audit-state="{{ $timeline['state'] }}" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
    <h5 class="serif mb-3" style="color:var(--text)">Lịch sử quyết định</h5>
    @if($timeline['state'] === 'pending')
        <p class="mb-0" style="font-size:.85rem;color:var(--text-muted)">Lịch sử quyết định đang chờ đồng bộ (audit pending). Các quyết định đã được lưu; lịch sử sẽ hiển thị khi bộ xử lý ghi xong.</p>
    @else
        @if($timeline['state'] === 'partial')
            <p style="font-size:.8rem;color:#f59e0b">Đang chờ đồng bộ {{ $timeline['expected'] - count($timeline['events']) }} sự kiện.</p>
        @endif
        <ol class="list-unstyled mb-0">
            @forelse($timeline['events'] as $event)
                <li class="py-2" style="border-bottom:1px solid var(--border);font-size:.85rem">
                    <strong style="color:var(--text)">{{ $eventLabels[$event['type']] ?? $event['type'] }}</strong>
                    <span style="color:var(--text-muted)">· {{ \Carbon\CarbonImmutable::parse($event['occurred_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}
                        · {{ $event['actor_name'] ?? 'Người dùng đã xóa' }} · {{ isset($event['case_version']) ? 'vụ việc v'.$event['case_version'] : 'biện pháp v'.($event['aggregate_version'] ?? '') }}</span>
                    @if(! empty($event['note']))<div style="color:var(--text-muted)">{{ $event['note'] }}</div>@endif
                </li>
            @empty
                <li style="font-size:.85rem;color:var(--text-muted)">Chưa có sự kiện.</li>
            @endforelse
        </ol>
    @endif
</div>
