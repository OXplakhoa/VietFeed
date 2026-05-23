@props(['count' => 4])

@for($i = 0; $i < $count; $i++)
<div class="skeleton-search-item">
    <div class="sk-thumb skeleton"></div>
    <div class="sk-stack">
        <div class="skeleton skeleton-text" style="width:80%"></div>
        <div class="skeleton skeleton-text sm" style="width:45%"></div>
    </div>
</div>
@endfor
