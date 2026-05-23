document.addEventListener('DOMContentLoaded', () => {
    initDarkMode();
    initNavbarScroll();
    initBookmarkToggle();
    initLiveSearch();
    initFadeIn();
    initCommentReplyToggle();
    initVerificationModal();
    initConfirmModal();
    initTicker();

    if (document.getElementById('infinite-scroll-anchor')) {
        initInfiniteScroll();
    }
});

// ── Dark Mode (CSS-driven sun/moon rotation swap) ──────────────
function initDarkMode() {
    const html = document.documentElement;
    const toggle = document.getElementById('dark-mode-toggle');

    const saved = localStorage.getItem('vf-theme') || 'dark';
    html.setAttribute('data-theme', saved);
    if (toggle) toggle.title = saved === 'dark' ? 'Chuyển sang chế độ sáng' : 'Chuyển sang chế độ tối';

    if (toggle) {
        toggle.addEventListener('click', () => {
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('vf-theme', next);
            toggle.title = next === 'dark' ? 'Chuyển sang chế độ sáng' : 'Chuyển sang chế độ tối';
        });
    }
}

// ── Auto-Hide Navbar + sticky category tabs ────────────────────
function initNavbarScroll() {
    const navbar = document.querySelector('.vf-navbar');
    const tabs   = document.querySelector('.category-tabs-bar');
    if (!navbar) return;

    let lastScrollY = window.scrollY;
    let ticking     = false;

    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            const y     = window.scrollY;
            const delta = y - lastScrollY;

            navbar.classList.toggle('scrolled', y > 50);

            if (y < 80) {
                navbar.classList.remove('navbar-hidden');
                tabs?.classList.remove('navbar-collapsed');
            } else if (Math.abs(delta) > 10) {
                const hide = delta > 0;
                navbar.classList.toggle('navbar-hidden', hide);
                tabs?.classList.toggle('navbar-collapsed', hide);
            }

            lastScrollY = y;
            ticking     = false;
        });
    }, { passive: true });
}

// ── Bookmark Toggle (AJAX) ─────────────────────────────────────
function initBookmarkToggle() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.bookmark-btn');
        if (!btn) return;
        e.preventDefault();
        const articleId = btn.dataset.articleId;
        if (!articleId) return;

        try {
            const res = await fetch('/bookmarks/toggle', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ article_id: articleId }),
            });

            if (res.status === 401) { window.location.href = '/login'; return; }

            const data = await res.json().catch(() => ({}));

            if (res.status === 403 && data.verify_required) {
                showVerifyToast();
                return;
            }

            if (!res.ok) {
                showToast(data.message || 'Có lỗi xảy ra, vui lòng thử lại', 'error');
                return;
            }

            const icon = btn.querySelector('.bi');

            if (data.action === 'added') {
                btn.classList.add('active');
                if (icon) icon.className = 'bi bi-bookmark-fill';
                showToast('Đã lưu bài viết', 'success');
            } else {
                btn.classList.remove('active');
                if (icon) icon.className = 'bi bi-bookmark';
                showToast('Đã bỏ lưu bài viết', 'info');
            }

            const countEl = btn.querySelector('.bookmark-count');
            if (countEl) countEl.textContent = data.count;
        } catch {
            showToast('Có lỗi xảy ra, vui lòng thử lại', 'error');
        }
    });
}

// ── Live Search ────────────────────────────────────────────────
function initLiveSearch() {
    const input = document.getElementById('live-search-input');
    const container = document.getElementById('live-search-results');
    if (!input || !container) return;

    let timer;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { container.innerHTML = ''; container.style.display = 'none'; return; }

        showSearchSkeleton(container);

        timer = setTimeout(async () => {
            try {
                const res = await fetch(`/api/live-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                renderSearchResults(container, await res.json(), q);
            } catch { container.style.display = 'none'; }
        }, 300);
    });

    document.addEventListener('click', (e) => {
        if (!input.closest('.position-relative').contains(e.target)) {
            container.style.display = 'none';
        }
    });
}

function showSearchSkeleton(container) {
    container.style.display = 'block';
    container.innerHTML = Array(4).fill(`
        <div class="skeleton-search-item">
            <div class="sk-thumb skeleton"></div>
            <div class="sk-stack">
                <div class="skeleton skeleton-text" style="width:80%"></div>
                <div class="skeleton skeleton-text sm" style="width:45%"></div>
            </div>
        </div>`).join('');
}

function renderSearchResults(container, results, q) {
    if (!results.length) {
        container.innerHTML = `<div class="p-3 text-center" style="color:var(--text-muted);font-size:.85rem">Không có kết quả cho "<strong>${escHtml(q)}</strong>"</div>`;
        container.style.display = 'block';
        return;
    }
    container.innerHTML = results.map(r => `
        <a href="${escHtml(r.url)}" class="live-search-item">
            ${r.image
                ? `<img src="${escHtml(r.image)}" alt="">`
                : `<div class="ls-placeholder"><i class="bi bi-newspaper"></i></div>`}
            <div class="ls-text">
                <div class="ls-title">${escHtml(r.title)}</div>
                <div class="ls-meta">${r.category ? escHtml(r.category) + ' · ' : ''}${r.date ? escHtml(r.date) : ''}</div>
            </div>
        </a>`).join('')
        + `<a href="/search?q=${encodeURIComponent(q)}" class="ls-see-all">Xem tất cả kết quả →</a>`;
    container.style.display = 'block';
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Infinite Scroll (homepage only) ───────────────────────────
function initInfiniteScroll() {
    const anchor = document.getElementById('infinite-scroll-anchor');
    const grid   = document.getElementById('articles-grid');
    if (!anchor || !grid) return;

    let page    = parseInt(anchor.dataset.nextPage || '2', 10);
    let hasMore = anchor.dataset.hasMore === 'true';
    let loading = false;

    const observer = new IntersectionObserver(async ([entry]) => {
        if (!entry.isIntersecting || loading || !hasMore) return;
        loading = true;
        const skeletons = appendSkeletons(grid, 3);

        try {
            const res  = await fetch(`/api/articles?page=${page}`, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            skeletons.forEach(s => s.remove());

            const tmp = document.createElement('div');
            tmp.innerHTML = data.html;
            [...tmp.children].forEach((card, i) => {
                card.style.transitionDelay = `${i * 80}ms`;
                card.classList.add('fade-in');
                grid.appendChild(card);
                requestAnimationFrame(() => card.classList.add('visible'));
            });

            hasMore = data.hasMore;
            page    = data.nextPage;
            anchor.dataset.hasMore  = hasMore;
            anchor.dataset.nextPage = page;
            if (!hasMore) observer.disconnect();
        } catch {
            skeletons.forEach(s => s.remove());
        } finally {
            loading = false;
        }
    }, { rootMargin: '300px' });

    observer.observe(anchor);
}

function appendSkeletons(grid, count) {
    return Array.from({ length: count }, () => {
        const el = document.createElement('div');
        el.className = 'col-sm-6 col-lg-4 mb-4';
        el.innerHTML = `<div class="skeleton-card"><div class="sk-img skeleton"></div><div class="sk-body"><div class="sk-line sk-w100 skeleton mb-2"></div><div class="sk-line sk-w80 skeleton mb-2"></div><div class="sk-line sk-w55 skeleton"></div></div></div>`;
        grid.appendChild(el);
        return el;
    });
}

// ── Fade-in on Scroll ──────────────────────────────────────────
function initFadeIn() {
    const els = document.querySelectorAll('.fade-in');
    if (!els.length) return;
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.08 });
    els.forEach(el => obs.observe(el));
}

// ── Comment Reply Toggle ───────────────────────────────────────
function initCommentReplyToggle() {
    document.addEventListener('click', e => {
        const btn = e.target.closest('.reply-toggle-btn');
        if (!btn) return;
        const target = document.getElementById(btn.dataset.target);
        if (target) {
            target.classList.toggle('d-none');
            target.querySelector('textarea')?.focus();
        }
    });
}

// ── Toast System ───────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-circle-fill', info: 'bi-info-circle-fill' };
    const el = document.createElement('div');
    el.className = `vf-toast toast show ${type}`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `
        <div class="toast-header">
            <i class="bi ${icons[type] || icons.info} me-2"></i>
            <strong class="me-auto">VietFeed</strong>
            <button type="button" class="btn-close btn-close-white" onclick="this.closest('.toast').remove()"></button>
        </div>
        <div class="toast-body">${escHtml(message)}</div>`;
    container.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

window.showToast = showToast;

// ── Email Verification Modal ───────────────────────────────────
function initVerificationModal() {
    const backdrop = document.getElementById('verify-modal-backdrop');
    if (!backdrop) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const btn  = document.getElementById('verify-resend-btn');

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeVerifyModal();
    });

    document.getElementById('verify-modal-close')?.addEventListener('click', closeVerifyModal);

    btn?.addEventListener('click', async () => {
        if (btn.classList.contains('sent')) return;
        btn.disabled = true;
        btn.textContent = 'Đang gửi…';
        try {
            await fetch('/email/verification-notification', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            });
            btn.textContent = '✓ Đã gửi! Kiểm tra hộp thư';
            btn.classList.add('sent');
            setTimeout(() => closeVerifyModal(), 4000);
        } catch {
            btn.disabled = false;
            btn.textContent = 'Gửi lại email xác minh';
        }
    });
}

function openVerifyModal() {
    const backdrop = document.getElementById('verify-modal-backdrop');
    if (backdrop) backdrop.classList.add('open');
}

function closeVerifyModal() {
    const backdrop = document.getElementById('verify-modal-backdrop');
    if (backdrop) backdrop.classList.remove('open');
}

function showVerifyToast() {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const el = document.createElement('div');
    el.className = 'vf-toast toast show info';
    el.setAttribute('role', 'alert');
    el.innerHTML = `
        <div class="toast-header">
            <i class="bi bi-info-circle-fill me-2"></i>
            <strong class="me-auto">VietFeed</strong>
            <button type="button" class="btn-close btn-close-white" onclick="this.closest('.toast').remove()"></button>
        </div>
        <div class="toast-body">
            Xác minh email để sử dụng tính năng này.
            <a href="#" onclick="event.preventDefault();openVerifyModal();this.closest('.toast').remove()"
               style="color:var(--accent);text-decoration:underline;margin-left:4px">Xác minh ngay →</a>
        </div>`;
    container.appendChild(el);
    setTimeout(() => el.remove(), 8000);
}

window.openVerifyModal = openVerifyModal;

// ── Confirm Modal ──────────────────────────────────────────────
let _confirmCallback = null;

function initConfirmModal() {
    const backdrop = document.getElementById('vf-confirm-backdrop');
    if (!backdrop) return;

    document.getElementById('vf-confirm-close')?.addEventListener('click', closeConfirmModal);
    document.getElementById('vf-cancel-btn')?.addEventListener('click', closeConfirmModal);

    document.getElementById('vf-confirm-btn')?.addEventListener('click', () => {
        const cb = _confirmCallback;
        closeConfirmModal();
        if (typeof cb === 'function') cb();
    });

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeConfirmModal();
    });

    document.addEventListener('keydown', (e) => {
        if (!backdrop.classList.contains('open')) return;
        if (e.key === 'Escape') { closeConfirmModal(); }
        if (e.key === 'Enter')  {
            e.preventDefault();
            const cb = _confirmCallback;
            closeConfirmModal();
            if (typeof cb === 'function') cb();
        }
    });
}

function vfConfirm({ title, message, onConfirm }) {
    const backdrop = document.getElementById('vf-confirm-backdrop');
    if (!backdrop) { if (typeof onConfirm === 'function') onConfirm(); return; }

    document.getElementById('vf-confirm-title').textContent  = title   || 'Xác nhận xóa';
    document.getElementById('vf-confirm-message').textContent = message || 'Bạn có chắc muốn thực hiện hành động này?';
    _confirmCallback = onConfirm;
    backdrop.classList.add('open');
    setTimeout(() => document.getElementById('vf-cancel-btn')?.focus(), 50);
}

function closeConfirmModal() {
    document.getElementById('vf-confirm-backdrop')?.classList.remove('open');
    _confirmCallback = null;
}

function vfConfirmForm(event, formOrEl, message, title) {
    event.preventDefault();
    const form = (formOrEl && formOrEl.tagName === 'FORM') ? formOrEl : formOrEl?.closest('form');
    vfConfirm({ title, message, onConfirm: () => form?.submit() });
}

window.vfConfirm     = vfConfirm;
window.vfConfirmForm = vfConfirmForm;

// ── Financial Ticker ──────────────────────────────────────────
let _tickerOpenMetric = null;
let _tickerData = {};

function initTicker() {
    const container = document.getElementById('vf-ticker');
    if (!container) return;

    try {
        const raw = container.dataset.ticker;
        if (raw) _tickerData = JSON.parse(raw);
    } catch (_) {}

    renderTicker(_tickerData);

    // Poll every 60s for fresh data
    setInterval(async () => {
        try {
            const res  = await fetch('/api/ticker', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            _flashTickerChanges(_tickerData, data);
            _tickerData = data;
            renderTicker(data);
            // Refresh open popover if any
            if (_tickerOpenMetric) {
                const pill = document.querySelector(`.vf-ticker-pill[data-metric="${_tickerOpenMetric}"]`);
                if (pill) _populatePopover(pill, data);
            }
        } catch (e) {
            console.warn('[Ticker] poll failed', e);
        }
    }, 60_000);

    document.querySelectorAll('.vf-ticker-pill').forEach(pill => {
        pill.addEventListener('click', () => _toggleTickerPopover(pill));
        pill.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                _toggleTickerPopover(pill);
            }
        });
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.vf-ticker-pill') && !e.target.closest('#vf-ticker-popover')) {
            _closeTickerPopover();
        }
    });

    document.getElementById('vf-ticker-popover')
        ?.querySelector('.vf-ticker-popover__close')
        ?.addEventListener('click', _closeTickerPopover);
}

function renderTicker(data) {
    _renderPill('usd', data?.usd);
    _renderPill('sjc', data?.sjc);
}

function _renderPill(metric, d) {
    const pill = document.querySelector(`.vf-ticker-pill[data-metric="${metric}"]`);
    if (!pill) return;

    if (!d) { pill.style.display = 'none'; return; }
    pill.style.display = '';

    const color = d.trend === 'up' ? '#22c55e' : 'var(--accent)';
    pill.style.setProperty('--spark-color', color);

    const valueEl  = pill.querySelector('.vf-ticker-pill__value');
    const deltaSpan = pill.querySelector('.vf-ticker-delta-text');
    const arrowIcon = pill.querySelector('.vf-ticker-arrow');

    if (valueEl)   valueEl.textContent  = metric === 'usd' ? _fmtVND(d.value) : _fmtGold(d.sell);
    if (deltaSpan) deltaSpan.textContent = (d.delta_pct >= 0 ? '+' : '') + (d.delta_pct ?? 0).toFixed(2) + '%';
    if (arrowIcon) arrowIcon.className  = `vf-ticker-arrow bi bi-arrow-${d.trend === 'up' ? 'up' : 'down'}-short`;

    _drawSparkline(pill.querySelector('.vf-ticker-spark'), d.history, 48, 20, 2.5);

    const dot = pill.querySelector('.vf-ticker-spark__dot');
    if (dot) dot.style.opacity = d.stale ? '0.25' : '';
}

function _drawSparkline(svg, history, W, H, pad) {
    if (!svg || !history || history.length < 2) return;

    const lo  = Math.min(...history);
    const hi  = Math.max(...history);
    const rng = hi - lo || 1;

    const pts = history.map((v, i) => [
        pad + (i / (history.length - 1)) * (W - 2 * pad),
        H - pad - ((v - lo) / rng) * (H - 2 * pad),
    ]);

    const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');

    svg.querySelector('.vf-ticker-spark__static')?.setAttribute('d', d);
    svg.querySelector('.vf-ticker-spark__sweep')?.setAttribute('d', d);

    const last = pts[pts.length - 1];
    const dot  = svg.querySelector('.vf-ticker-spark__dot');
    if (dot) { dot.setAttribute('cx', last[0].toFixed(1)); dot.setAttribute('cy', last[1].toFixed(1)); }
}

function _toggleTickerPopover(pill) {
    const metric  = pill.dataset.metric;
    const popover = document.getElementById('vf-ticker-popover');
    if (!popover) return;

    if (_tickerOpenMetric === metric && popover.classList.contains('open')) {
        _closeTickerPopover();
        return;
    }

    _tickerOpenMetric = metric;
    _populatePopover(pill, _tickerData);

    // Position below the pill (fixed coords from viewport)
    const rect  = pill.getBoundingClientRect();
    const popW  = 272;
    const vw    = window.innerWidth;
    let   left  = rect.left;
    if (left + popW > vw - 12) left = vw - popW - 12;

    popover.style.top  = `${rect.bottom + 8}px`;
    popover.style.left = `${left}px`;
    popover.style.display = 'block';
    popover.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => popover.classList.add('open'));
}

function _populatePopover(pill, data) {
    const metric  = pill.dataset.metric;
    const d       = data?.[metric];
    const popover = document.getElementById('vf-ticker-popover');
    if (!popover || !d) return;

    const color = d.trend === 'up' ? '#22c55e' : 'var(--accent)';
    popover.style.setProperty('--spark-color', color);

    popover.querySelector('.vf-ticker-popover__title').textContent =
        metric === 'usd' ? 'Tỷ giá USD / VND' : 'Vàng SJC 9999';

    popover.querySelector('.vf-ticker-popover__badge').innerHTML =
        d.stale ? '<span class="vf-ticker-stale-tag">Dữ liệu cũ</span>' : '';

    const valuesEl = popover.querySelector('.vf-ticker-popover__values');
    const sign     = d.delta_pct >= 0 ? '+' : '';
    const pctStr   = `${sign}${(d.delta_pct ?? 0).toFixed(4)}%`;

    if (metric === 'usd') {
        valuesEl.innerHTML = `
            <div class="vf-ticker-pop-row">
                <span class="vf-ticker-pop-label">1 USD</span>
                <span class="vf-ticker-pop-val">${_fmtVND(d.value)}</span>
            </div>
            <div class="vf-ticker-pop-row">
                <span class="vf-ticker-pop-label">Thay đổi</span>
                <span class="vf-ticker-pop-delta" style="color:${color}">${pctStr}</span>
            </div>`;
    } else {
        valuesEl.innerHTML = `
            <div class="vf-ticker-pop-row">
                <span class="vf-ticker-pop-label">Mua vào</span>
                <span class="vf-ticker-pop-val">${_fmtGoldFull(d.buy)}</span>
            </div>
            <div class="vf-ticker-pop-row">
                <span class="vf-ticker-pop-label">Bán ra</span>
                <span class="vf-ticker-pop-val">${_fmtGoldFull(d.sell)}</span>
            </div>
            <div class="vf-ticker-pop-row">
                <span class="vf-ticker-pop-label">Thay đổi</span>
                <span class="vf-ticker-pop-delta" style="color:${color}">${pctStr}</span>
            </div>`;
    }

    _drawSparkline(popover.querySelector('.vf-ticker-popover__chart'), d.history, 300, 80, 4);

    popover.querySelector('.vf-ticker-popover__meta').innerHTML =
        `<span class="vf-ticker-pop-updated">Cập nhật: ${_viTimeAgo(d.updated_at)}</span>` +
        `<span class="vf-ticker-pop-source">Nguồn: ${escHtml(d.source)}</span>`;
}

function _closeTickerPopover() {
    const popover = document.getElementById('vf-ticker-popover');
    if (!popover) return;
    popover.classList.remove('open');
    popover.setAttribute('aria-hidden', 'true');
    _tickerOpenMetric = null;
    popover.addEventListener('transitionend', () => {
        if (!popover.classList.contains('open')) popover.style.display = 'none';
    }, { once: true });
}

function _flashTickerChanges(prev, next) {
    ['usd', 'sjc'].forEach(metric => {
        const p = prev?.[metric];
        const n = next?.[metric];
        if (!p || !n) return;

        const changed = metric === 'usd' ? p.value !== n.value : p.sell !== n.sell;
        if (!changed) return;

        const valueEl = document.querySelector(`.vf-ticker-pill[data-metric="${metric}"] .vf-ticker-pill__value`);
        if (!valueEl) return;
        valueEl.style.opacity = '0';
        setTimeout(() => { valueEl.style.opacity = ''; }, 300);
    });
}

function _fmtVND(v) {
    if (!v) return '—';
    return new Intl.NumberFormat('vi-VN').format(v) + ' ₫';
}
function _fmtGold(v) {
    if (!v) return '—';
    return (v / 1_000_000).toFixed(1) + ' tr';
}
function _fmtGoldFull(v) {
    if (!v) return '—';
    return new Intl.NumberFormat('vi-VN').format(v) + ' ₫';
}
function _viTimeAgo(iso) {
    if (!iso) return '—';
    const diff = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60)    return 'vừa xong';
    if (diff < 3600)  return `${Math.floor(diff / 60)} phút trước`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} giờ trước`;
    return `${Math.floor(diff / 86400)} ngày trước`;
}
