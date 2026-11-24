@props(['comment', 'article', 'depth' => 0, 'viewerCanComment' => false, 'viewerCanReport' => false])

<div class="comment-block {{ $depth > 0 ? 'mt-2' : 'mb-3' }}">
    <div class="d-flex gap-2 align-items-start">
        <div class="comment-avatar">
            <i class="bi bi-person"></i>
        </div>
        <div class="flex-fill">
            <div class="comment-meta d-flex align-items-center gap-2 mb-1">
                <strong style="font-size:.875rem;color:var(--text)">{{ $comment->user?->name ?? 'Người dùng đã xóa' }}</strong>
                @if($comment->user?->isPro())
                <span class="vf-pro-badge"><i class="bi bi-gem"></i> PRO</span>
                @endif
                <span>{{ $comment->created_at->diffForHumans() }}</span>
                @if(($comment->user_id !== null && auth()->id() === $comment->user_id))
                    <span class="badge rounded-pill" style="background:rgba(230,57,70,.15);color:var(--accent);font-size:.7rem">Của bạn</span>
                @endif
                @if($comment->is_hidden)
                    <span class="badge rounded-pill" style="background:rgba(245,158,11,.15);color:#f59e0b;font-size:.7rem"><i class="bi bi-eye-slash me-1"></i>Bình luận đang bị ẩn</span>
                @endif
                @auth
                <div class="ms-auto">
                    <x-comment-kebab :comment="$comment" :article="$article" :viewer-can-report="$viewerCanReport" />
                </div>
                @endauth
            </div>

            {{-- Hidden comments are only ever rendered for their author and admins (filtered in ArticleController). --}}
            @if($comment->account_deleted)
            <div class="comment-body" style="color:var(--text-muted);font-style:italic">Bình luận đã bị xóa</div>
            @else
            <div class="comment-body" @if($comment->is_hidden) style="opacity:.7" @endif>{{ $comment->body }}</div>
            @endif
            @if($comment->is_hidden)
            <div style="color:var(--text-muted);font-style:italic;font-size:.8rem;margin-top:.25rem">
                @if(\Illuminate\Support\Facades\Gate::allows(\App\Authorization\Permissions::COMMENTS))
                    Bình luận này đang bị ẩn khỏi người dùng khác (chỉ tác giả và quản trị viên nhìn thấy).
                @else
                    Bình luận của bạn đã bị ẩn do vi phạm quy định cộng đồng. Chỉ bạn và quản trị viên nhìn thấy.
                @endif
            </div>
            @endif

            @if(!$comment->is_hidden)
            <div class="d-flex align-items-center gap-2 mt-2">
                @auth
                    @if($depth === 0 && $viewerCanComment)
                    <button class="reply-toggle-btn btn btn-sm"
                            data-target="reply-form-{{ $comment->id }}"
                            style="background:none;border:none;color:var(--text-muted);font-size:.8rem;padding:0">
                        <i class="bi bi-reply me-1"></i>Trả lời
                    </button>
                    @endif
                    @if(($comment->user_id !== null && auth()->id() === $comment->user_id) || \Illuminate\Support\Facades\Gate::allows(\App\Authorization\Permissions::COMMENTS))
                    <form action="{{ route('comments.destroy', $comment) }}" method="POST" class="m-0">
                        @csrf @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('Xóa bình luận này?')"
                                style="background:none;border:none;color:var(--text-muted);font-size:.8rem;padding:0;transition:color .2s"
                                onmouseover="this.style.color='var(--accent)'"
                                onmouseout="this.style.color='var(--text-muted)'">
                            <i class="bi bi-trash"></i> Xóa
                        </button>
                    </form>
                    @endif
                @endauth
            </div>
            @endif

            @auth
            @if($depth === 0 && $viewerCanComment)
            <div id="reply-form-{{ $comment->id }}" class="reply-form-wrap d-none">
                <form action="{{ route('comments.store', $article->slug) }}" method="POST">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                    <textarea name="body" class="vf-textarea mb-2" rows="2"
                              placeholder="Viết câu trả lời…" required></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-accent" style="font-size:.8rem;padding:.35rem .9rem">
                            Gửi
                        </button>
                        <button type="button"
                                class="reply-toggle-btn"
                                data-target="reply-form-{{ $comment->id }}"
                                style="background:none;border:none;color:var(--text-muted);font-size:.8rem;cursor:pointer">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Edit form --}}
            @if(($comment->user_id !== null && auth()->id() === $comment->user_id))
            <div id="edit-comment-{{ $comment->id }}" class="reply-form-wrap d-none mt-2">
                <form action="{{ route('comments.update', $comment) }}" method="POST">
                    @csrf @method('PUT')
                    <textarea name="body" class="vf-textarea mb-2" rows="2" required>{{ $comment->body }}</textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-accent" style="font-size:.8rem;padding:.35rem .9rem">
                            Lưu
                        </button>
                        <button type="button"
                                onclick="document.getElementById('edit-comment-{{ $comment->id }}').classList.add('d-none')"
                                style="background:none;border:none;color:var(--text-muted);font-size:.8rem;cursor:pointer">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
            @endif
            @endauth
        </div>
    </div>

    @if($comment->replies && $comment->replies->count() > 0)
    <div class="comment-replies">
        @foreach($comment->replies as $reply)
            <x-comment :comment="$reply" :article="$article" :depth="1" :viewer-can-comment="$viewerCanComment" :viewer-can-report="$viewerCanReport" />
        @endforeach
    </div>
    @endif
</div>
