<x-app-layout>
    <x-slot name="title">Thêm chủ đề — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="{{ route('admin.categories.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i>Chủ đề
            </a>
            <span style="color:var(--text-muted)">/</span>
            <h3 class="mb-0" style="font-family:'Playfair Display',serif;font-size:1.3rem">Thêm chủ đề mới</h3>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-5">
                <div class="p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
                    <form action="{{ route('admin.categories.store') }}" method="POST">
                        @csrf
                        @include('admin.categories._form')
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn-accent" style="padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;border:none;cursor:pointer">
                                <i class="bi bi-plus-lg me-1"></i>Thêm chủ đề
                            </button>
                            <a href="{{ route('admin.categories.index') }}" class="btn btn-sm" style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:8px">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
