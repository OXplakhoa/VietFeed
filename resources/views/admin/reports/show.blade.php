<x-app-layout>
    <x-slot name="title">Chi tiết báo cáo #{{ $report->id }} — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="mb-4">
            <a href="{{ route('admin.reports.index') }}" style="font-size:.85rem;color:var(--text-muted);text-decoration:none">
                <i class="bi bi-arrow-left me-1"></i>Quay lại hộp thư báo cáo
            </a>
        </div>

        <div class="row g-4">
            {{-- Report Details --}}
            <div class="col-lg-8">
                <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h4 class="serif mb-0" style="color:var(--text)">Báo cáo #{{ $report->id }}</h4>
                        <span class="badge rounded-pill" style="background:{{ $report->statusColor() }}20;color:{{ $report->statusColor() }};font-size:.8rem;border:1px solid {{ $report->statusColor() }}40">
                            {{ $report->statusLabel() }}
                        </span>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Người báo cáo</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $report->reporter?->name ?? '—' }}</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">{{ $report->reporter?->email ?? '' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Thời gian báo cáo</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $report->created_at->format('d/m/Y H:i') }}</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">{{ $report->created_at->diffForHumans() }}</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Lý do</div>
                            <span class="badge rounded-pill" style="background:var(--surface-alt);color:var(--text);font-size:.8rem;border:1px solid var(--border)">
                                {{ $report->reasonLabel() }}
                            </span>
                        </div>
                        <div class="col-md-6">
                            <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Người xử lý</div>
                            <div style="font-size:.9rem;color:var(--text)">{{ $report->admin?->name ?? 'Chưa xử lý' }}</div>
                        </div>
                    </div>

                    @if($report->description)
                    <div class="mb-3">
                        <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem">Mô tả</div>
                        <div class="p-3" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text);line-height:1.6">
                            {{ $report->description }}
                        </div>
                    </div>
                    @endif

                    {{-- Status Update --}}
                    <form action="{{ route('admin.reports.update', $report) }}" method="POST" class="d-flex flex-wrap gap-2 align-items-center">
                        @csrf @method('PUT')
                        <div style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Cập nhật trạng thái:</div>
                        <select name="status" class="form-select form-select-sm" style="background:var(--surface-alt);border-color:var(--border);color:var(--text);max-width:180px">
                            <option value="pending" {{ $report->status === 'pending' ? 'selected' : '' }}>Chờ duyệt</option>
                            <option value="reviewed" {{ $report->status === 'reviewed' ? 'selected' : '' }}>Đã xem xét</option>
                            <option value="actioned" {{ $report->status === 'actioned' ? 'selected' : '' }}>Đã xử lý</option>
                            <option value="dismissed" {{ $report->status === 'dismissed' ? 'selected' : '' }}>Bác bỏ</option>
                            <option value="false_report" {{ $report->status === 'false_report' ? 'selected' : '' }}>Báo cáo sai</option>
                        </select>
                        <button type="submit" class="btn btn-sm"
                                style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                            <i class="bi bi-check me-1"></i>Cập nhật
                        </button>
                    </form>
                </div>

                {{-- Comment in question --}}
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <h5 class="serif mb-3" style="color:var(--text)">Bình luận bị báo cáo</h5>
                    <div class="d-flex gap-2 align-items-start mb-3">
                        <div class="comment-avatar">
                            <i class="bi bi-person"></i>
                        </div>
                        <div class="flex-fill">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong style="font-size:.875rem;color:var(--text)">{{ $report->comment?->user?->name ?? '—' }}</strong>
                                <span style="font-size:.75rem;color:var(--text-muted)">{{ $report->comment?->created_at?->diffForHumans() ?? '' }}</span>
                            </div>
                            <div style="font-size:.9rem;color:var(--text);line-height:1.6">
                                {{ $report->comment?->body ?? 'Bình luận không tồn tại.' }}
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('articles.show', $report->comment?->article?->slug ?? '') }}"
                           target="_blank" style="font-size:.82rem;color:var(--accent);text-decoration:none">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Xem bài viết
                        </a>
                        @if($report->comment && !$report->comment->is_hidden)
                        <form action="{{ route('comments.destroy', $report->comment) }}" method="POST" class="m-0"
                              onsubmit="return confirm('Ẩn bình luận này?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="font-size:.82rem;color:#ef4444;background:none;border:none;padding:0;cursor:pointer">
                                <i class="bi bi-eye-slash me-1"></i>Ẩn bình luận
                            </button>
                        </form>
                        @elseif($report->comment && $report->comment->is_hidden)
                        <span style="font-size:.82rem;color:var(--text-muted)">
                            <i class="bi bi-eye-slash me-1"></i>Đã ẩn
                        </span>
                        @endif
                    </div>
                </div>

                {{-- Other Reports on this comment --}}
                @if($otherReports->isNotEmpty())
                <div class="p-4 mt-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <h5 class="serif mb-3" style="color:var(--text)">Báo cáo khác về bình luận này ({{ $otherReports->count() }})</h5>
                    <div class="d-flex flex-column gap-3">
                        @foreach($otherReports as $other)
                        <div class="p-3" style="background:var(--surface-alt);border-radius:8px">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="font-size:.85rem;color:var(--text);font-weight:500">{{ $other->reporter?->name ?? '—' }}</span>
                                    <span style="font-size:.75rem;color:var(--text-muted)">{{ $other->created_at->diffForHumans() }}</span>
                                </div>
                                <span class="badge rounded-pill" style="background:{{ $other->statusColor() }}20;color:{{ $other->statusColor() }};font-size:.7rem;border:1px solid {{ $other->statusColor() }}40">
                                    {{ $other->statusLabel() }}
                                </span>
                            </div>
                            <div style="font-size:.82rem;color:var(--text-muted)">
                                <strong style="color:var(--text)">{{ $other->reasonLabel() }}:</strong> {{ $other->description ?? 'Không có mô tả.' }}
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- Sidebar: Take Action --}}
            <div class="col-lg-4">
                <div class="sticky-top" style="top:90px;z-index:100">
                    @if($report->status !== 'actioned')
                    <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-3" style="color:var(--text)">
                            <i class="bi bi-hammer me-2" style="color:var(--accent)"></i>Biện pháp xử lý
                        </h5>
                        <form action="{{ route('admin.sanctions.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $report->comment?->user_id ?? '' }}">
                            <input type="hidden" name="report_id" value="{{ $report->id }}">
                            <input type="hidden" name="comment_id" value="{{ $report->comment_id }}">

                            <div class="mb-3">
                                <label class="vf-label" style="font-size:.8rem">Người vi phạm</label>
                                <div class="p-2" style="background:var(--surface-alt);border-radius:8px;font-size:.85rem;color:var(--text)">
                                    {{ $report->comment?->user?->name ?? '—' }}<br>
                                    <span style="color:var(--text-muted);font-size:.8rem">{{ $report->comment?->user?->email ?? '' }}</span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="vf-label" style="font-size:.8rem">Loại xử lý</label>
                                <select name="type" class="form-select form-select-sm" required
                                        style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="">Chọn loại</option>
                                    <option value="warning">Cảnh cáo</option>
                                    <option value="mute">Cấm bình luận</option>
                                    <option value="temporary_ban">Cấm tạm thời</option>
                                    <option value="permanent_ban">Cấm vĩnh viễn</option>
                                </select>
                            </div>

                            <div class="mb-3" id="duration-field">
                                <label class="vf-label" style="font-size:.8rem">Thời hạn (ngày)</label>
                                <select name="duration_days" class="form-select form-select-sm"
                                        style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    <option value="3">3 ngày</option>
                                    <option value="7" selected>7 ngày</option>
                                    <option value="14">14 ngày</option>
                                    <option value="30">30 ngày</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="vf-label" style="font-size:.8rem">Lý do xử lý</label>
                                <textarea name="reason" class="vf-textarea" rows="3" required
                                          placeholder="Nêu rõ lý do áp dụng biện pháp xử lý..."></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="w-100 btn-accent" style="font-size:.85rem">
                                    <i class="bi bi-hammer me-1"></i>Áp dụng
                                </button>
                            </div>
                        </form>
                    </div>
                    @else
                    <div class="p-4 mb-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                        <h5 class="serif mb-3" style="color:var(--text)">Kết quả xử lý</h5>
                        @if($report->sanction)
                        <div class="mb-2">
                            <span class="badge rounded-pill" style="background:{{ $report->sanction->typeColor() }}20;color:{{ $report->sanction->typeColor() }};font-size:.8rem;border:1px solid {{ $report->sanction->typeColor() }}40">
                                {{ $report->sanction->typeLabel() }}
                            </span>
                        </div>
                        <div style="font-size:.85rem;color:var(--text);line-height:1.6">
                            {{ $report->sanction->reason }}
                        </div>
                        @if($report->sanction->expires_at)
                        <div class="mt-2" style="font-size:.8rem;color:var(--text-muted)">
                            <i class="bi bi-clock me-1"></i>Hết hạn: {{ $report->sanction->expires_at->format('d/m/Y H:i') }}
                        </div>
                        @endif
                        <a href="{{ route('admin.sanctions.show', $report->sanction) }}" class="d-block mt-3" style="font-size:.85rem;color:var(--accent);text-decoration:none">
                            Xem chi tiết biện pháp xử lý →
                        </a>
                        @else
                        <p style="font-size:.85rem;color:var(--text-muted)">Báo cáo đã được đánh dấu là đã xử lý nhưng không có biện pháp xử lý nào được tạo.</p>
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
                if (val === 'warning' || val === 'permanent_ban') {
                    durationField.style.display = 'none';
                } else {
                    durationField.style.display = 'block';
                }
            }

            if (typeSelect) {
                typeSelect.addEventListener('change', toggleDuration);
                toggleDuration();
            }
        })();
    </script>
    @endpush
</x-app-layout>
