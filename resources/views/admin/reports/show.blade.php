<x-app-layout>
    <x-slot name="title">Chi tiết báo cáo #{{ $report->id }} — Quản trị VietFeed</x-slot>

    @php
        $stateLabel = ['visible' => 'Đang hiển thị', 'hidden' => 'Đang bị ẩn', 'deleted' => 'Bình luận đã bị xóa'][$commentState];
        $authorName = $author?->name ?? 'Người dùng đã xóa';
    @endphp

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="mb-4">
            <a href="{{ route('admin.reports.index') }}" style="font-size:.85rem;color:var(--text-muted);text-decoration:none">
                <i class="bi bi-arrow-left me-1"></i>Quay lại hộp thư báo cáo
            </a>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                {{-- Reported comment: snapshot taken when the first report was filed + live state --}}
                <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h4 class="serif mb-0" style="color:var(--text)">Bình luận bị báo cáo</h4>
                        <span class="badge rounded-pill" style="background:{{ $report->statusColor() }}20;color:{{ $report->statusColor() }};font-size:.8rem;border:1px solid {{ $report->statusColor() }}40">
                            {{ $report->statusLabel() }}
                        </span>
                    </div>
                    <div class="d-flex gap-2 align-items-start mb-3">
                        <div class="comment-avatar"><i class="bi bi-person"></i></div>
                        <div class="flex-fill">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong style="font-size:.875rem;color:var(--text)">{{ $authorName }}</strong>
                                @if($author)<span style="font-size:.75rem;color:var(--text-muted)">{{ $author->email }}</span>@endif
                            </div>
                            <div style="font-size:.9rem;color:var(--text);line-height:1.6">{{ $report->comment_body }}</div>
                            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem">Nội dung tại thời điểm báo cáo</div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 align-items-center" style="font-size:.82rem">
                        <span class="badge rounded-pill" style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border)">{{ $stateLabel }}</span>
                        @if($article)
                            <a href="{{ route('articles.show', $article->slug) }}" target="_blank" style="color:var(--accent);text-decoration:none">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Xem bài viết: {{ Str::limit($article->title, 60) }}
                            </a>
                        @else
                            <span style="color:var(--text-muted)">Bài viết không còn tồn tại</span>
                        @endif
                        @if($comment)
                        <form action="{{ route('comments.destroy', $comment) }}" method="POST" class="m-0"
                              onsubmit="return confirm('Xóa bình luận này? Các báo cáo vẫn được giữ lại.')">
                            @csrf @method('DELETE')
                            <button type="submit" style="color:#ef4444;background:none;border:none;padding:0;cursor:pointer">
                                <i class="bi bi-trash me-1"></i>Xóa bình luận
                            </button>
                        </form>
                        @endif
                    </div>
                </div>

                {{-- All reports on this comment --}}
                <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <h5 class="serif mb-3" style="color:var(--text)">Các báo cáo về bình luận này ({{ $reports->count() }})</h5>
                    <div class="d-flex flex-column gap-3">
                        @foreach($reports as $r)
                        <div class="p-3" style="background:var(--surface-alt);border-radius:8px">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="font-size:.85rem;color:var(--text);font-weight:500">{{ $r->reporter?->name ?? 'Người dùng đã xóa' }}</span>
                                    <span style="font-size:.75rem;color:var(--text-muted)">{{ $r->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                <span class="badge rounded-pill" style="background:{{ $r->statusColor() }}20;color:{{ $r->statusColor() }};font-size:.7rem;border:1px solid {{ $r->statusColor() }}40">
                                    {{ $r->statusLabel() }}
                                </span>
                            </div>
                            <div style="font-size:.82rem;color:var(--text-muted)">
                                <strong style="color:var(--text)">{{ $r->reasonLabel() }}:</strong> {{ $r->description ?: 'Không có mô tả.' }}
                            </div>
                            @if($r->decided_at)
                            <div class="mt-2" style="font-size:.78rem;color:var(--text-muted)">
                                Quyết định bởi {{ $r->admin?->name ?? '—' }} lúc {{ $r->decided_at->format('d/m/Y H:i') }}@if($r->decision_note) — {{ $r->decision_note }}@endif
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Author sanction history --}}
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <h5 class="serif mb-3" style="color:var(--text)">Lịch sử xử lý của {{ $authorName }}</h5>
                    @forelse($history as $h)
                        <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom:1px solid var(--border);font-size:.85rem">
                            <div>
                                <span class="badge rounded-pill" style="background:{{ $h->typeColor() }}20;color:{{ $h->typeColor() }};border:1px solid {{ $h->typeColor() }}40">{{ $h->typeLabel() }}</span>
                                <span class="ms-2" style="color:var(--text)">{{ $h->reason }}</span>
                            </div>
                            <div style="color:var(--text-muted);white-space:nowrap">
                                {{ $h->statusLabel() }} · {{ $h->created_at->format('d/m/Y') }}
                                <a href="{{ route('admin.sanctions.show', $h) }}" class="ms-2" style="color:var(--accent);text-decoration:none">Chi tiết</a>
                            </div>
                        </div>
                    @empty
                        <p class="mb-0" style="font-size:.85rem;color:var(--text-muted)">Chưa có biện pháp xử lý nào.</p>
                    @endforelse
                </div>

                @include('admin.partials.moderation-timeline', ['timeline' => $timeline])

                @if($orphaned->isNotEmpty())
                <div class="p-4 mt-4" id="orphaned-sanctions" role="alert" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35);border-radius:12px">
                    <h5 class="serif mb-2" style="color:#ef4444">Biện pháp xử lý không gắn với quyết định của vụ việc</h5>
                    <p style="font-size:.82rem;color:var(--text-muted)">Yêu cầu áp dụng bị chậm quá thời hạn khóa nên vụ việc đã được quyết định theo cách khác. Hãy xem xét và thu hồi nếu cần.</p>
                    @foreach($orphaned as $o)
                        <a href="{{ route('admin.sanctions.show', $o) }}" class="d-block" style="font-size:.85rem;color:var(--accent);text-decoration:none">{{ $o->typeLabel() }} — {{ $o->reason }} ({{ $o->statusLabel() }})</a>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Sidebar: decide --}}
            <div class="col-lg-4">
                <div class="sticky-top" style="top:90px;z-index:100">
                    @if($pendingReport)
                    <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-1" style="color:var(--text)"><i class="bi bi-hammer me-2" style="color:var(--accent)"></i>Biện pháp xử lý</h5>
                        <p style="font-size:.78rem;color:var(--text-muted)">Áp dụng cho tất cả {{ $pendingCount }} báo cáo đang chờ về bình luận này.</p>
                        @if($author)
                        <form action="{{ route('admin.sanctions.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $author->id }}">
                            <input type="hidden" name="report_id" value="{{ $pendingReport->id }}">

                            <div class="mb-3">
                                <label class="vf-label" style="font-size:.8rem">Loại xử lý</label>
                                <select name="type" class="form-select form-select-sm" required style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="">Chọn loại</option>
                                    <option value="warning">Cảnh cáo</option>
                                    <option value="mute">Cấm bình luận</option>
                                    <option value="temporary_ban">Cấm tạm thời</option>
                                    <option value="permanent_ban">Cấm vĩnh viễn</option>
                                </select>
                            </div>
                            <div class="mb-3" id="duration-field">
                                <label class="vf-label" style="font-size:.8rem">Thời hạn (ngày)</label>
                                <select name="duration_days" class="form-select form-select-sm" style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="3">3 ngày</option>
                                    <option value="7" selected>7 ngày</option>
                                    <option value="14">14 ngày</option>
                                    <option value="30">30 ngày</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="vf-label" style="font-size:.8rem">Lý do xử lý</label>
                                <textarea name="reason" class="vf-textarea" rows="3" required placeholder="Nêu rõ lý do áp dụng biện pháp xử lý..."></textarea>
                            </div>
                            @if($commentState === 'visible')
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="hide_comment" id="hide_comment" value="1">
                                <label class="form-check-label" for="hide_comment" style="font-size:.82rem;color:var(--text)">Ẩn bình luận bị báo cáo</label>
                            </div>
                            @endif
                            <button type="submit" class="w-100 btn-accent" style="font-size:.85rem"><i class="bi bi-hammer me-1"></i>Áp dụng</button>
                        </form>
                        @else
                        <p style="font-size:.82rem;color:var(--text-muted)">Tài khoản tác giả đã bị xóa nên không thể áp dụng biện pháp xử lý.</p>
                        @endif
                    </div>

                    <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-3" style="color:var(--text)">Đóng báo cáo không xử phạt</h5>
                        <form action="{{ route('admin.reports.update', $pendingReport) }}" method="POST">
                            @csrf @method('PUT')
                            <div class="mb-2">
                                <select name="status" class="form-select form-select-sm" style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="dismissed">Bác bỏ (không vi phạm)</option>
                                    <option value="false_report">Báo cáo sai (lạm dụng tính năng)</option>
                                </select>
                            </div>
                            <textarea name="note" class="vf-textarea mb-2" rows="2" maxlength="2000" placeholder="Ghi chú (không bắt buộc)"></textarea>
                            <button type="submit" class="btn btn-sm w-100" style="background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:6px">Xác nhận</button>
                        </form>
                    </div>
                    @else
                    <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-3" style="color:var(--text)">Kết quả xử lý</h5>
                        <div class="mb-2"><span class="badge rounded-pill" style="background:{{ $report->statusColor() }}20;color:{{ $report->statusColor() }};border:1px solid {{ $report->statusColor() }}40">{{ $report->statusLabel() }}</span></div>
                        <div style="font-size:.82rem;color:var(--text-muted)">
                            Bởi {{ $report->admin?->name ?? '—' }}@if($report->decided_at) lúc {{ $report->decided_at->format('d/m/Y H:i') }}@endif
                            @if($report->decision_note)<br>{{ $report->decision_note }}@endif
                        </div>
                        @if($resolution)
                        <div class="mt-3" style="font-size:.85rem;color:var(--text)">
                            {{ $resolution->typeLabel() }} — {{ $resolution->reason }}
                            <div class="mt-2" style="font-size:.8rem;color:var(--text-muted)">
                                Kháng cáo: <strong style="color:var(--text)">{{ $resolution->appealStatusLabel() }}</strong>
                                @if($resolution->appeal_decision_note)<br>Ghi chú quyết định kháng cáo: {{ $resolution->appeal_decision_note }}@endif
                            </div>
                            <a href="{{ route('admin.sanctions.show', $resolution) }}" class="d-block mt-2" style="color:var(--accent);text-decoration:none">Xem chi tiết biện pháp xử lý →</a>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        (function() {
            const typeSelect = document.querySelector('select[name="type"]');
            const durationField = document.getElementById('duration-field');
            function toggleDuration() {
                if (!typeSelect || !durationField) return;
                const val = typeSelect.value;
                durationField.style.display = (val === 'warning' || val === 'permanent_ban') ? 'none' : 'block';
            }
            if (typeSelect) { typeSelect.addEventListener('change', toggleDuration); toggleDuration(); }
        })();
    </script>
    @endpush
</x-app-layout>
