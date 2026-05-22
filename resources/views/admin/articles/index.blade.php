<x-app-layout>
    <x-slot name="title">Bài viết — Quản trị VietFeed</x-slot>

    {{-- Bulk-destroy form lives completely OUTSIDE the table so it never nests with per-row delete forms --}}
    <form id="bulk-form" action="{{ route('admin.articles.bulk-destroy') }}" method="POST" style="display:none">
        @csrf
        {{-- ids[] inputs are injected here by submitBulk() before submit --}}
    </form>

    <div class="container-xl py-4">
        @include('admin.partials.nav')

        <div class="d-flex align-items-center justify-content-between mb-4">
            <h3 class="mb-0" style="font-family:'Playfair Display',serif">
                <i class="bi bi-newspaper me-2" style="color:var(--accent)"></i>Bài viết
                <span style="font-size:.9rem;font-weight:400;color:var(--text-muted)">
                    ({{ number_format($articles->total()) }})
                </span>
            </h3>
        </div>

        {{-- Filter Bar --}}
        <form method="GET" action="{{ route('admin.articles.index') }}" class="mb-4">
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" name="q" value="{{ request('q') }}"
                           class="form-control form-control-sm"
                           placeholder="Tìm theo tiêu đề hoặc mô tả…"
                           style="background:var(--surface);border-color:var(--border);color:var(--text)">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select form-select-sm"
                            style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="">Tất cả chủ đề</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->slug }}" {{ request('category') == $cat->slug ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="source" class="form-select form-select-sm"
                            style="background:var(--surface);border-color:var(--border);color:var(--text)">
                        <option value="">Tất cả nguồn</option>
                        @foreach($sources as $src)
                        <option value="{{ $src->id }}" {{ request('source') == $src->id ? 'selected' : '' }}>
                            {{ $src->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm w-100"
                            style="background:var(--accent);color:#fff;border:none;border-radius:6px">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>

        {{-- Bulk action bar (no form wrapper — uses submitBulk() JS) --}}
        <div id="bulk-bar" class="mb-3 p-2 d-none align-items-center gap-3"
             style="background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.3);border-radius:8px">
            <span id="bulk-count" style="font-size:.85rem;color:var(--text)">0 bài đã chọn</span>
            <button type="button" onclick="submitBulk()"
                    style="font-size:.82rem;background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:.2rem .7rem;cursor:pointer">
                <i class="bi bi-trash me-1"></i>Xóa đã chọn
            </button>
            <button type="button" id="bulk-cancel"
                    style="font-size:.82rem;background:none;border:none;color:var(--text-muted);cursor:pointer">Hủy</button>
        </div>

        {{-- Article table — no form wrapper; per-row delete forms are standalone --}}
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color:var(--text)">
                    <thead style="background:var(--surface-alt);font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                        <tr>
                            <th class="ps-3 py-3" style="width:36px">
                                <input type="checkbox" id="select-all" style="cursor:pointer">
                            </th>
                            <th>Tiêu đề</th>
                            <th>Chủ đề</th>
                            <th>Nguồn</th>
                            <th><i class="bi bi-bookmark"></i></th>
                            <th>Ngày đăng</th>
                            <th class="pe-3 text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($articles as $a)
                    <tr style="border-color:var(--border)">
                        <td class="ps-3" style="vertical-align:middle">
                            {{-- standalone checkbox — NOT inside any form --}}
                            <input type="checkbox" class="row-check" value="{{ $a->id }}" style="cursor:pointer">
                        </td>
                        <td style="vertical-align:middle;max-width:320px">
                            <a href="{{ route('admin.articles.show', $a) }}"
                               style="font-size:.85rem;color:var(--text);text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                {{ $a->title }}
                            </a>
                        </td>
                        <td style="vertical-align:middle;font-size:.8rem;white-space:nowrap">
                            <a href="{{ route('categories.show', $a->category->slug) }}" target="_blank"
                               style="color:var(--text-muted);text-decoration:none">{{ $a->category->name }}</a>
                        </td>
                        <td style="vertical-align:middle;font-size:.78rem;color:var(--text-muted);white-space:nowrap">
                            {{ Str::limit($a->source->name, 20) }}
                        </td>
                        <td style="vertical-align:middle;font-size:.8rem">{{ $a->bookmarks_count }}</td>
                        <td style="vertical-align:middle;font-size:.78rem;color:var(--text-muted);white-space:nowrap">
                            {{ $a->published_at?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="pe-3 text-end" style="vertical-align:middle;white-space:nowrap">
                            <a href="{{ route('admin.articles.edit', $a) }}"
                               style="color:#60a5fa;font-size:.8rem;text-decoration:none;margin-right:.6rem">
                                <i class="bi bi-pencil"></i>
                            </a>
                            {{-- Standalone per-row delete form — NOT nested inside anything --}}
                            <form action="{{ route('admin.articles.destroy', $a) }}" method="POST"
                                  class="d-inline"
                                  onsubmit="return confirm('Xóa bài viết này?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        style="color:#ef4444;font-size:.8rem;background:none;border:none;padding:0;cursor:pointer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-4" style="color:var(--text-muted)">
                            Không tìm thấy bài viết nào.
                        </td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $articles->links() }}</div>
    </div>

    @push('scripts')
    <script>
    function submitBulk() {
        const checked = Array.from(document.querySelectorAll('.row-check:checked'));
        if (checked.length === 0) return;
        if (!confirm('Xóa ' + checked.length + ' bài viết đã chọn?')) return;

        const form = document.getElementById('bulk-form');
        // Remove any ids[] inputs from a previous (cancelled) attempt
        form.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
        // Inject the selected IDs
        checked.forEach(function (c) {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'ids[]';
            inp.value = c.value;
            form.appendChild(inp);
        });
        form.submit();
    }

    (function () {
        const selectAll  = document.getElementById('select-all');
        const checks     = () => document.querySelectorAll('.row-check');
        const bulkBar    = document.getElementById('bulk-bar');
        const bulkCount  = document.getElementById('bulk-count');
        const bulkCancel = document.getElementById('bulk-cancel');

        function updateBar() {
            const selected = document.querySelectorAll('.row-check:checked').length;
            if (selected > 0) {
                bulkBar.classList.remove('d-none');
                bulkBar.classList.add('d-flex');
                bulkCount.textContent = selected + ' bài đã chọn';
            } else {
                bulkBar.classList.add('d-none');
                bulkBar.classList.remove('d-flex');
            }
            if (selectAll) {
                selectAll.indeterminate = selected > 0 && selected < checks().length;
                selectAll.checked = checks().length > 0 && selected === checks().length;
            }
        }

        selectAll?.addEventListener('change', () => {
            checks().forEach(c => c.checked = selectAll.checked);
            updateBar();
        });

        document.addEventListener('change', e => {
            if (e.target.classList.contains('row-check')) updateBar();
        });

        bulkCancel?.addEventListener('click', () => {
            checks().forEach(c => c.checked = false);
            if (selectAll) selectAll.checked = false;
            updateBar();
        });
    })();
    </script>
    @endpush
</x-app-layout>
