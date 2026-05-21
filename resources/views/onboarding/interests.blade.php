<x-app-layout>
    <x-slot name="title">Chọn chủ đề yêu thích — VietFeed</x-slot>

    <div class="container py-5" style="max-width:680px">
        <div class="text-center mb-4">
            <h1 class="serif mb-2" style="font-size:2rem;font-weight:700;color:var(--text)">
                Tuỳ chỉnh feed của bạn
            </h1>
            <p style="color:var(--text-muted)">
                Chọn các chủ đề bạn quan tâm để nhận được tin tức phù hợp nhất.
            </p>
        </div>

        <form action="{{ route('onboarding.interests.save') }}" method="POST">
            @csrf
            <div class="row g-3 mb-4">
                @php
                $icons = [
                    'thoi-su'   => 'bi-newspaper',
                    'the-gioi'  => 'bi-globe2',
                    'kinh-doanh'=> 'bi-briefcase',
                    'cong-nghe' => 'bi-cpu',
                    'the-thao'  => 'bi-trophy',
                    'giai-tri'  => 'bi-music-note-beamed',
                    'suc-khoe'  => 'bi-heart-pulse',
                    'giao-duc'  => 'bi-book',
                ];
                @endphp
                @foreach($categories as $cat)
                <div class="col-6 col-md-3">
                    <label class="interest-card w-100 {{ in_array($cat->id, $selected) ? 'selected' : '' }}"
                           for="cat_{{ $cat->id }}"
                           onclick="this.classList.toggle('selected')">
                        <input type="checkbox"
                               id="cat_{{ $cat->id }}"
                               name="categories[]"
                               value="{{ $cat->id }}"
                               {{ in_array($cat->id, $selected) ? 'checked' : '' }}>
                        <i class="bi {{ $icons[$cat->slug] ?? 'bi-tag' }} interest-icon"></i>
                        <div class="interest-name">{{ $cat->name }}</div>
                    </label>
                </div>
                @endforeach
            </div>

            <div class="d-flex gap-3 justify-content-center">
                <button type="submit" class="btn-accent px-4 py-2">
                    <i class="bi bi-check2 me-1"></i>Lưu sở thích
                </button>
                <a href="{{ route('home') }}"
                   style="color:var(--text-muted);text-decoration:none;display:flex;align-items:center;font-size:.875rem">
                    Bỏ qua
                </a>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        document.querySelectorAll('.interest-card').forEach(card => {
            card.addEventListener('click', () => {
                const cb = card.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = !cb.checked;
            });
        });
    </script>
    @endpush
</x-app-layout>
