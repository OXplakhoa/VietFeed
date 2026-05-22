@php $src = $source ?? null; @endphp

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Tên nguồn tin <span style="color:var(--accent)">*</span></label>
    <input type="text" name="name" value="{{ old('name', $src?->name) }}"
           class="form-control @error('name') is-invalid @enderror"
           placeholder="vd: VnExpress - Công nghệ"
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">URL trang chủ <span style="color:var(--accent)">*</span></label>
    <input type="url" name="url" value="{{ old('url', $src?->url) }}"
           class="form-control @error('url') is-invalid @enderror"
           placeholder="https://vnexpress.net"
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">URL RSS Feed <span style="color:var(--accent)">*</span></label>
    <input type="url" name="feed_url" value="{{ old('feed_url', $src?->feed_url) }}"
           class="form-control @error('feed_url') is-invalid @enderror"
           placeholder="https://vnexpress.net/rss/cong-nghe.rss"
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    @error('feed_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">URL Logo <span style="color:var(--text-muted)">(tùy chọn)</span></label>
    <input type="url" name="logo_url" value="{{ old('logo_url', $src?->logo_url) }}"
           class="form-control @error('logo_url') is-invalid @enderror"
           placeholder="https://..."
           style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
    @error('logo_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label class="form-label" style="font-size:.85rem;color:var(--text-muted);font-weight:500">Chủ đề <span style="color:var(--accent)">*</span></label>
    <select name="category_id" class="form-select @error('category_id') is-invalid @enderror"
            style="background:var(--surface-alt);border-color:var(--border);color:var(--text)">
        <option value="">— Chọn chủ đề —</option>
        @foreach($categories as $cat)
        <option value="{{ $cat->id }}" {{ old('category_id', $src?->category_id) == $cat->id ? 'selected' : '' }}>
            {{ $cat->name }}
        </option>
        @endforeach
    </select>
    @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="form-check">
    <input type="hidden" name="is_active" value="0">
    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
           {{ old('is_active', $src?->is_active ?? true) ? 'checked' : '' }}>
    <label class="form-check-label" for="is_active" style="font-size:.875rem;color:var(--text)">
        Kích hoạt nguồn tin (lấy bài tự động)
    </label>
</div>
