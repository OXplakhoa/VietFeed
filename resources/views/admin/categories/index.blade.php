<x-app-layout>
    <x-slot name="title">Chủ đề — Quản trị VietFeed</x-slot>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-tag me-2" style="color:var(--accent)"></i>Chủ đề
            </h3>
            <a href="{{ route('admin.categories.create') }}" class="btn-accent" style="text-decoration:none;padding:.45rem 1rem;border-radius:8px;font-size:.875rem">
                <i class="bi bi-plus-lg me-1"></i>Thêm chủ đề
            </a>
        </div>

        {{-- Filter Bar --}}
        <form method="GET" action="{{ route('admin.categories.index') }}" class="mb-4">
            <div class="row g-2">
                <div class="col-md-6">
                    <input type="text" name="q" value="{{ request('q') }}"
                           class="form-control form-control-sm"
                           placeholder="Tìm theo tên chủ đề…"
                           style="background:var(--surface);border-color:var(--border);color:var(--text)">
                </div>
                <div class="col-md-2">
                    <select name="sort" class="form-select form-select-sm"
                            style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="name"           {{ request('sort','name') === 'name'           ? 'selected' : '' }}>Tên</option>
                        <option value="articles_count" {{ request('sort') === 'articles_count'        ? 'selected' : '' }}>Số bài viết</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="dir" class="form-select form-select-sm"
                            style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="asc"  {{ request('dir','asc') === 'asc'  ? 'selected' : '' }}>Tăng dần</option>
                        <option value="desc" {{ request('dir') === 'desc'        ? 'selected' : '' }}>Giảm dần</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm w-100"
                            style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                @if(request()->hasAny(['q','sort','dir']))
                <div class="col-auto">
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-sm"
                       style="background:var(--surface-alt);color:var(--text-muted);border:1px solid var(--border);border-radius:6px">
                        Xóa bộ lọc
                    </a>
                </div>
                @endif
            </div>
        </form>

        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color:var(--text)">
                    <thead style="background:var(--surface-alt);font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                        <tr>
                            <th class="ps-3 py-3">Tên chủ đề</th>
                            <th>Slug</th>
                            <th>Bài viết</th>
                            <th>Nguồn tin</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($categories as $cat)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3 py-3" style="vertical-align:middle;font-weight:500;font-size:.875rem">{{ $cat->name }}</td>
                        <td style="vertical-align:middle">
                            <code style="font-size:.78rem;background:var(--surface-alt);border:1px solid var(--border);border-radius:4px;padding:.1rem .4rem;color:var(--text-secondary)">{{ $cat->slug }}</code>
                        </td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ number_format($cat->articles_count) }}</td>
                        <td style="vertical-align:middle;font-size:.85rem">{{ $cat->sources_count }}</td>
                        <td class="pe-3 text-end" style="vertical-align:middle;white-space:nowrap">
                            <a href="{{ route('admin.categories.edit', $cat) }}"
                               style="color:#60a5fa;font-size:.8rem;text-decoration:none;margin-right:.75rem">
                                <i class="bi bi-pencil me-1"></i>Sửa
                            </a>
                            <form action="{{ route('admin.categories.destroy', $cat) }}" method="POST" class="d-inline"
                                  onsubmit="vfConfirmForm(event, this, 'Chủ đề này sẽ bị xóa vĩnh viễn.')">
                                @csrf @method('DELETE')
                                <button type="submit" style="color:#ef4444;font-size:.8rem;background:none;border:none;padding:0;cursor:pointer">
                                    <i class="bi bi-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center py-4" style="color:var(--text-muted)">Chưa có chủ đề nào.</td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $categories->links() }}</div>
    </div>
</x-app-layout>
