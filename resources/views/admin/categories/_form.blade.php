@php $cat = $category ?? null; @endphp

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Tên chủ đề <span style="color:var(--accent)">*</span></label>
    <input type="text" name="name" id="cat-name" value="{{ old('name', $cat?->name) }}"
           class="form-control @error('name') is-invalid @enderror"
           placeholder="vd: Công nghệ"
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Slug <span style="color:var(--accent)">*</span></label>
    <input type="text" name="slug" id="cat-slug" value="{{ old('slug', $cat?->slug) }}"
           class="form-control @error('slug') is-invalid @enderror"
           placeholder="vd: cong-nghe"
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem">
        Chỉ dùng chữ thường tiếng Anh, số và dấu gạch ngang (không dấu tiếng Việt).
    </div>
    @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

@push('scripts')
<script>
// Auto-slug from name — only fills if slug is still empty
document.getElementById('cat-name')?.addEventListener('input', function () {
    const slugField = document.getElementById('cat-slug');
    if (slugField && !slugField.dataset.manual) {
        slugField.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
});
document.getElementById('cat-slug')?.addEventListener('input', function () {
    this.dataset.manual = '1';
});
</script>
@endpush
