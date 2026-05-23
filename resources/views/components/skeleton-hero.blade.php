<div class="skeleton-hero">
    <div class="sk-hero-main skeleton"></div>
    <div class="sk-hero-side">
        @for($i = 0; $i < 3; $i++)
        <div class="sk-hero-sm">
            <div class="sk-hero-sm-img skeleton"></div>
            <div class="sk-hero-sm-body">
                <div class="skeleton skeleton-text"></div>
                <div class="skeleton skeleton-text" style="width:70%"></div>
                <div class="skeleton skeleton-text sm" style="width:40%"></div>
            </div>
        </div>
        @endfor
    </div>
</div>
