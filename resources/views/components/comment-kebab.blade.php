@props(['comment', 'article'])

<div class="dropdown">
    <button class="btn btn-sm dropdown-toggle"
            type="button"
            data-bs-toggle="dropdown"
            style="background:none;border:none;color:var(--text-muted);font-size:.8rem;padding:0"
            onmouseover="this.style.color='var(--text)'"
            onmouseout="this.style.color='var(--text-muted)'">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;min-width:160px">
        @if(auth()->id() === $comment->user_id)
            <li>
                <button type="button"
                        class="dropdown-item"
                        style="font-size:.82rem;color:var(--text);background:none"
                        onclick="document.getElementById('edit-comment-{{ $comment->id }}').classList.toggle('d-none')">
                    <i class="bi bi-pencil me-2" style="color:var(--text-muted)"></i>Sửa
                </button>
            </li>
            <li>
                <form action="{{ route('comments.destroy', $comment) }}" method="POST" class="m-0"
                      onsubmit="return confirm('Xóa bình luận này?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="dropdown-item" style="font-size:.82rem;color:#ef4444;background:none">
                        <i class="bi bi-trash me-2"></i>Xóa
                    </button>
                </form>
            </li>
        @elseif(!auth()->user()->isAdmin())
            <li>
                <button type="button"
                        class="dropdown-item"
                        style="font-size:.82rem;color:var(--text);background:none"
                        data-bs-toggle="modal"
                        data-bs-target="#report-modal-{{ $comment->id }}">
                    <i class="bi bi-flag me-2" style="color:var(--accent)"></i>Báo cáo
                </button>
            </li>
        @endif
    </ul>
</div>

{{-- Report Modal --}}
<div class="modal fade" id="report-modal-{{ $comment->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:14px">
            <div class="modal-header" style="border-bottom:1px solid var(--border)">
                <h5 class="modal-title serif" style="color:var(--text)">Báo cáo bình luận</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('reports.store', $comment) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">
                        Báo cáo của bạn sẽ được gửi đến đội ngũ kiểm duyệt. Danh tính của bạn được giữ bí mật.
                    </p>

                    <div class="mb-3">
                        <label class="vf-label">Lý do báo cáo</label>
                        <div class="d-flex flex-column gap-2">
                            @foreach([
                                'hate_speech' => 'Phát ngôn thù địch, phân biệt chủng tộc',
                                'harassment' => 'Quấy rối, bắt nạt, đe dọa',
                                'spam' => 'Spam, quảng cáo, nội dung không liên quan',
                                'misinformation' => 'Thông tin sai lệch, giả mạo',
                                'sexual_content' => 'Nội dung tình dục, không phù hợp',
                                'other' => 'Khác',
                            ] as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="reason" id="reason-{{ $comment->id }}-{{ $value }}"
                                       value="{{ $value }}"
                                       {{ $loop->first ? 'required' : '' }}>
                                <label class="form-check-label" for="reason-{{ $comment->id }}-{{ $value }}"
                                       style="font-size:.85rem;color:var(--text)">
                                    {{ $label }}
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="mb-1">
                        <label for="report-desc-{{ $comment->id }}" class="vf-label">Mô tả thêm (không bắt buộc)</label>
                        <textarea id="report-desc-{{ $comment->id }}" name="description"
                                  class="vf-textarea" rows="3" maxlength="2000"
                                  placeholder="Cung cấp thêm ngữ cảnh để giúp đội ngũ kiểm duyệt..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border)">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal" style="text-decoration:none">Hủy</button>
                    <button type="submit" style="background:var(--accent);color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;font-size:.875rem">
                        <i class="bi bi-flag me-1"></i>Gửi báo cáo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
