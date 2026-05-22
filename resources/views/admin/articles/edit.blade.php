<x-app-layout>
    <x-slot name="title">Sửa bài viết — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.articles.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Bài viết
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.2rem;max-width:600px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                {{ Str::limit($article->title, 60) }}
            </h3>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <form action="{{ route('admin.articles.update', $article) }}" method="POST">
                        @csrf @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Tiêu đề <span style="color:var(--accent)">*</span></label>
                            <input type="text" name="title" value="{{ old('title', $article->title) }}"
                                   class="form-control @error('title') is-invalid @enderror"
                                   style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Mô tả</label>
                            <textarea name="description" rows="5"
                                      class="form-control @error('description') is-invalid @enderror"
                                      style="background:var(--surface-alt);border-color:var(--border);color:var(--text);resize:vertical">{{ old('description', $article->description) }}</textarea>
                            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">URL ảnh đại diện</label>
                            <input type="url" name="image_url" value="{{ old('image_url', $article->image_url) }}"
                                   class="form-control @error('image_url') is-invalid @enderror"
                                   style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            @error('image_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if($article->image_url)
                            <div class="mt-2">
                                <img src="{{ $article->image_url }}" alt="" style="height:80px;border-radius:6px;object-fit:cover">
                            </div>
                            @endif
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Chủ đề <span style="color:var(--accent)">*</span></label>
                                <select name="category_id" class="form-select @error('category_id') is-invalid @enderror"
                                        style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" {{ old('category_id', $article->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Nguồn tin <span style="color:var(--accent)">*</span></label>
                                <select name="source_id" class="form-select @error('source_id') is-invalid @enderror"
                                        style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                                    @foreach($sources as $src)
                                    <option value="{{ $src->id }}" {{ old('source_id', $article->source_id) == $src->id ? 'selected' : '' }}>{{ $src->name }}</option>
                                    @endforeach
                                </select>
                                @error('source_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Ngày đăng</label>
                            <input type="datetime-local" name="published_at"
                                   value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}"
                                   class="form-control @error('published_at') is-invalid @enderror"
                                   style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
                            @error('published_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn-accent" style="padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;border:none;cursor:pointer">
                                <i class="bi bi-check-lg me-1"></i>Lưu thay đổi
                            </button>
                            <a href="{{ route('admin.articles.index') }}" class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="p-3" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <div class="sidebar-title mb-3">Thông tin</div>
                    @foreach([
                        ['Slug',       $article->slug],
                        ['URL gốc',    Str::limit($article->original_url, 40)],
                        ['Tạo lúc',   $article->created_at->format('d/m/Y H:i')],
                        ['Slug hiện tại', $article->slug],
                    ] as [$k, $v])
                    <div class="mb-2">
                        <div style="font-size:.72rem;color:var(--text-muted)">{{ $k }}</div>
                        <div style="font-size:.8rem;color:var(--text);word-break:break-all">{{ $v }}</div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-3 p-3" style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:12px">
                    <div style="font-size:.85rem;font-weight:500;color:#ef4444;margin-bottom:.5rem">Xóa bài viết</div>
                    <form action="{{ route('admin.articles.destroy', $article) }}" method="POST"
                          onsubmit="return confirm('Xóa bài viết này?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:.8rem;color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:.3rem .75rem;cursor:pointer">
                            <i class="bi bi-trash me-1"></i>Xóa bài viết
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
