<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{-- Apply theme BEFORE any CSS loads to prevent FOUC (flash of dark on light pages, or vice versa) --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('vf-theme') || 'dark';
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'VietFeed') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    @vite(['resources/css/app.css'])
    @stack('styles')
</head>
<body>

{{-- ── Glassmorphism Navbar ───────────────────────────────────── --}}
<nav class="navbar navbar-expand-lg vf-navbar">
    <div class="container-xl">
        <a class="navbar-brand" href="{{ route('home') }}">
            Viet<span class="accent">Feed</span>
        </a>

        <button class="navbar-toggler border-0 ms-auto me-2" type="button"
                data-bs-toggle="collapse" data-bs-target="#vfNav" aria-expanded="false">
            <i class="bi bi-list" style="font-size:1.4rem;color:var(--text)"></i>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="vfNav">

            {{-- Search Bar --}}
            <div class="position-relative me-3 my-2 my-lg-0" style="width:240px">
                <form action="{{ route('search') }}" method="GET" class="vf-search-form d-flex" autocomplete="off">
                    <input id="live-search-input" type="text" name="q"
                           class="form-control form-control-sm"
                           placeholder="Tìm kiếm bài viết…"
                           value="{{ request('q') }}"
                           autocomplete="off">
                    <button type="submit" class="btn btn-sm" aria-label="Tìm kiếm">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
                <div id="live-search-results"
                     class="position-absolute w-100"
                     style="display:none;top:calc(100% + 6px);z-index:1050"></div>
            </div>

            {{-- Right side --}}
            <div class="d-flex align-items-center gap-2 mt-2 mt-lg-0">
                <button id="dark-mode-toggle" aria-label="Chế độ tối/sáng">
                    <i class="bi bi-sun"></i>
                </button>

                @auth
                    <a href="{{ route('bookmarks.index') }}"
                       class="d-none d-lg-flex align-items-center gap-1"
                       style="color:var(--text-muted);background:var(--surface-alt);border:1px solid var(--border);border-radius:8px;padding:.38rem .7rem;font-size:.85rem;text-decoration:none;transition:color .2s"
                       title="Bài viết đã lưu">
                        <i class="bi bi-bookmark"></i>
                    </a>
                    <div class="dropdown vf-user-dropdown">
                        <button class="btn btn-sm dropdown-toggle d-flex align-items-center gap-1"
                                type="button" data-bs-toggle="dropdown"
                                style="color:var(--text);background:var(--surface-alt);border:1px solid var(--border);border-radius:8px;font-size:.85rem;padding:.38rem .75rem">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('profile.edit') }}">
                                <i class="bi bi-person me-2"></i>Hồ sơ</a></li>
                            <li><a class="dropdown-item" href="{{ route('bookmarks.index') }}">
                                <i class="bi bi-bookmark me-2"></i>Bài đã lưu</a></li>
                            <li><a class="dropdown-item" href="{{ route('onboarding.interests') }}">
                                <i class="bi bi-heart me-2"></i>Sở thích</a></li>
                            @if(auth()->user()->isAdmin())
                            <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">
                                <i class="bi bi-speedometer2 me-2"></i>Quản trị</a></li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="{{ route('logout') }}" method="POST" class="m-0">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-2"></i>Đăng xuất
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="btn-outline-accent" style="text-decoration:none">Đăng nhập</a>
                    <a href="{{ route('register') }}" class="btn-accent" style="text-decoration:none">Đăng ký</a>
                @endauth
            </div>

        </div>
    </div>
</nav>

{{-- ── Sticky Category Tabs (public pages only) ────────────────── --}}
@unless(request()->routeIs('admin.*'))
<div class="category-tabs-bar">
    <div class="container-xl">
        <nav class="nav flex-nowrap">
            <a href="{{ route('home') }}"
               class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">Tất cả</a>
            @foreach(\App\Models\Category::all() as $cat)
            <a href="{{ route('categories.show', $cat->slug) }}"
               class="nav-link {{ request()->is('categories/'.$cat->slug) ? 'active' : '' }}">
                {{ $cat->name }}
            </a>
            @endforeach
        </nav>
    </div>
</div>
@endunless

{{-- Flash Messages --}}
@if(session('success'))
<div class="container-xl mt-3">
    <div class="alert alert-dismissible d-flex align-items-center gap-2 mb-0"
         style="background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#22c55e;border-radius:10px"
         role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <span>{{ session('success') }}</span>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="opacity:.6"></button>
    </div>
</div>
@endif
@if(session('error'))
<div class="container-xl mt-3">
    <div class="alert alert-dismissible d-flex align-items-center gap-2 mb-0"
         style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:var(--accent);border-radius:10px"
         role="alert">
        <i class="bi bi-exclamation-circle-fill"></i>
        <span>{{ session('error') }}</span>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="opacity:.6"></button>
    </div>
</div>
@endif
@if($errors->any())
<div class="container-xl mt-3">
    <div class="alert alert-dismissible d-flex align-items-center gap-2 mb-0"
         style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:var(--accent);border-radius:10px"
         role="alert">
        <i class="bi bi-exclamation-circle-fill"></i>
        <span>{{ $errors->first() }}</span>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="opacity:.6"></button>
    </div>
</div>
@endif

{{-- Toast Container --}}
<div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999"></div>

@if(session('show_verify_banner'))
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.createElement('div');
        el.className = 'vf-toast toast show info';
        el.setAttribute('role', 'alert');
        el.innerHTML = `
            <div class="toast-header">
                <i class="bi bi-envelope-check-fill me-2"></i>
                <strong class="me-auto">VietFeed</strong>
                <button type="button" class="btn-close btn-close-white" onclick="this.closest('.toast').remove()"></button>
            </div>
            <div class="toast-body">
                Xác minh email để sử dụng đầy đủ tính năng.
                <a href="#" onclick="event.preventDefault();openVerifyModal();this.closest('.toast').remove()"
                   style="color:var(--accent);text-decoration:underline;margin-left:4px">Kiểm tra hộp thư →</a>
            </div>`;
        document.getElementById('toast-container')?.appendChild(el);
        setTimeout(() => el.remove(), 10000);
    });
</script>
@endif

@if(session('verify_email_required'))
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof openVerifyModal === 'function') openVerifyModal();
    });
</script>
@endif

@auth
@if(!auth()->user()->hasVerifiedEmail())
{{-- Verification Modal --}}
<div id="verify-modal-backdrop" class="vf-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="verify-modal-title">
    <div class="vf-modal">
        <button id="verify-modal-close" class="vf-modal-close" aria-label="Đóng">&times;</button>
        <span class="vf-modal-icon">📧</span>
        <h2 id="verify-modal-title" class="vf-modal-title">Xác minh email</h2>
        <p class="vf-modal-email">Chúng tôi đã gửi link xác minh đến<br><strong>{{ auth()->user()->email }}</strong></p>
        <button id="verify-resend-btn" type="button">Gửi lại email xác minh</button>
        <p class="vf-modal-hint">Không nhận được ? Kiểm tra thư mục spam hoặc nhấn gửi lại.</p>
    </div>
</div>
@endif
@endauth

{{-- Main Content --}}
<main>{{ $slot }}</main>

{{-- Footer --}}
<footer class="vf-footer">
    <div class="container-xl">
        <div class="row align-items-center">
            <div class="col-md-6">
                <span class="serif fw-bold" style="color:var(--text)">
                    Viet<span style="color:var(--accent)">Feed</span>
                </span>
                <span class="ms-2">— Tổng hợp tin tức tiếng Việt</span>
            </div>
            <div class="col-md-6 text-md-end mt-2 mt-md-0">
                <span>&copy; {{ date('Y') }} VietFeed &middot; Môn Lập trình mã nguồn mở</span>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@vite(['resources/js/app.js'])
@stack('scripts')
</body>
</html>
