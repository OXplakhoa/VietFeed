@props([])

<div class="welcome-banner text-center py-5">
    <div class="py-4">
        <div class="welcome-banner-icon mb-3">
            <i class="bi bi-radioactive"></i>
        </div>
        <h2 class="welcome-banner-title mb-2">Chào mừng đến với VietFeed</h2>
        <p class="welcome-banner-subtitle mb-4">
            Nguồn tin tức tiếng Việt cá nhân hoá — theo dõi các chủ đề bạn quan tâm
        </p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="{{ route('categories.index') }}" class="btn btn-accent btn-lg">
                <i class="bi bi-compass me-1"></i> Khám phá chủ đề
            </a>
            @guest
            <a href="{{ route('login') }}" class="btn btn-outline-accent btn-lg">
                <i class="bi bi-box-arrow-in-right me-1"></i> Đăng nhập
            </a>
            @endguest
        </div>
    </div>
</div>
