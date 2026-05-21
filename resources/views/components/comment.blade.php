@props(['comment', 'article', 'depth' => 0])

<div class="comment-block {{ $depth > 0 ? 'mt-2' : 'mb-3' }}">
    <div class="d-flex gap-2 align-items-start">
        <div class="comment-avatar">
            <i class="bi bi-person"></i>
        </div>
        <div class="flex-fill">
            <div class="comment-meta d-flex align-items-center gap-2 mb-1">
                <strong style="font-size:.875rem;color:var(--text)">{{ $comment->user->name }}</strong>
                <span>{{ $comment->created_at->diffForHumans() }}</span>
                @if(auth()->id() === $comment->user_id)
                    <span class="badge rounded-pill" style="background:rgba(230,57,70,.15);color:var(--accent);font-size:.7rem">Của bạn</span>
                @endif
            </div>
            <div class="comment-body">{{ $comment->body }}</div>
            <div class="d-flex align-items-center gap-2 mt-2">
                @auth
                    @if($depth === 0)
                    <button class="reply-toggle-btn btn btn-sm"
                            data-target="reply-form-{{ $comment->id }}"
                            style="background:none;border:none;color:var(--text-muted);font-size:.8rem;padding:0">
                        <i class="bi bi-reply me-1"></i>Trả lời
                    </button>
                    @endif
                    @if(auth()->id() === $comment->user_id || auth()->user()->isAdmin())
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

            @auth
            @if($depth === 0)
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
            @endauth
        </div>
    </div>

    @if($comment->replies && $comment->replies->count() > 0)
    <div class="comment-replies">
        @foreach($comment->replies as $reply)
            <x-comment :comment="$reply" :article="$article" :depth="1" />
        @endforeach
    </div>
    @endif
</div>
