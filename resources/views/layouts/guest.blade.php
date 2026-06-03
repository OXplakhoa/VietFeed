@props([
    'title' => 'Đăng nhập',
    'eyebrow' => 'Phiên bản tuyển chọn',
    'headline' => 'Đọc tin Việt theo gu của bạn.',
    'manifesto' => 'Một không gian đọc tin được biên tập chỉn chu — ít nhiễu, nhiều chiều sâu, và luôn đúng nhịp thời sự.',
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VietFeed') }} — {{ $title }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=laravel-2">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}?v=laravel-2" sizes="32x32">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body class="vf-auth-body">
    <div class="vf-auth-shell">
        <div class="vf-auth-grid">
            <aside class="vf-auth-editorial-panel">
                <a href="{{ route('home') }}" class="vf-auth-brand">
                    <span>Viet</span><span class="accent">Feed</span>
                </a>

                <div class="vf-auth-copy">
                    <div class="vf-auth-eyebrow">{{ $eyebrow }}</div>
                    <h1 class="vf-auth-headline">{{ $headline }}</h1>
                    <p class="vf-auth-manifesto">{{ $manifesto }}</p>
                </div>

                <div class="vf-auth-editorial-note">
                    <div class="vf-auth-note-line"></div>
                    <p>Tin nóng, bình luận và các chuyển động đáng chú ý được gom lại trong một nhịp đọc gọn, sang và dễ theo dõi.</p>
                </div>
            </aside>

            <main class="vf-auth-form-panel">
                <div class="vf-auth-card shadow-lg">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.pw-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.closest('.pw-wrap').querySelector('input');
                const icon = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bi bi-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'bi bi-eye';
                }
            });
        });
    </script>
</body>
</html>
