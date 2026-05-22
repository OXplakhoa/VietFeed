<x-app-layout>
    <x-slot name="title">Sửa nguồn tin — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.sources.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Nguồn tin
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.3rem">Sửa: {{ $source->name }}</h3>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <form action="{{ route('admin.sources.update', $source) }}" method="POST">
                        @csrf @method('PUT')
                        @include('admin.sources._form')
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn-accent" style="padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;border:none;cursor:pointer">
                                <i class="bi bi-check-lg me-1"></i>Lưu thay đổi
                            </button>
                            <a href="{{ route('admin.sources.index') }}" class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px">Hủy</a>
                        </div>
                    </form>
                </div>

                {{-- Danger zone --}}
                <div class="mt-3 p-3" style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:12px">
                    <div style="font-size:.85rem;font-weight:500;color:#ef4444;margin-bottom:.5rem">Vùng nguy hiểm</div>
                    <form action="{{ route('admin.sources.destroy', $source) }}" method="POST"
                          onsubmit="return confirm('Xóa nguồn tin này? Bài viết đã lấy sẽ không bị xóa.')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:.8rem;color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:.3rem .75rem;cursor:pointer">
                            <i class="bi bi-trash me-1"></i>Xóa nguồn tin
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
