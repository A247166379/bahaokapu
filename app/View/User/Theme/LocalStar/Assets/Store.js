(function () {
    'use strict';
    const list = document.querySelector('.store-products');
    if (!list) return;
    const storefront = window.Storefront;
    if (!storefront) return;
    const t = storefront.t;
    const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const plain = value => new DOMParser().parseFromString(String(value || ''), 'text/html').body.textContent || '';
    const safeImage = value => {
        const path = String(value || '').trim();
        if (!path || /[\x00-\x20\\]/.test(path)) return '/favicon.ico';
        if (path.startsWith('/') && !path.startsWith('//')) return path;
        try { const image = new URL(path, location.origin); return ['http:', 'https:'].includes(image.protocol) ? image.href : '/favicon.ico'; } catch (_) { return '/favicon.ico'; }
    };
    const initialCategory = typeof getVar === 'function' ? Number(getVar('CAT_ID') || 0) : 0;
    let category = initialCategory > 0 ? String(initialCategory) : 'recommend';
    const serverCategory = category;
    const clearPriceFilter = function () {
        const current = new URL(location.href);
        if (!current.searchParams.has('price_type')) return;
        current.searchParams.delete('price_type');
        history.replaceState(history.state, '', current.pathname + current.search + current.hash);
        storefront.syncLocaleLinks();
    };
    clearPriceFilter();
    let products = [];
    let sequence = 0;
    let activeRequest;
    let search = '';
    const searchInput = document.querySelector('.store-search-form input');
    const catalogueTitle = document.querySelector('[data-store-catalog-title]');
    const categories = document.querySelector('.store-categories');
    const desktopCategories = document.querySelector('.store-category-slot');
    const mobileCategories = document.querySelector('.store-mobile-categories');
    const layoutMedia = window.matchMedia('(max-width: 767px)');
    const updateLayout = function () {
        if (!document.contains(list)) {
            if (layoutMedia.removeEventListener) layoutMedia.removeEventListener('change', updateLayout);
            else layoutMedia.removeListener(updateLayout);
            return;
        }
        const target = layoutMedia.matches ? mobileCategories : desktopCategories;
        if (categories && target && categories.parentElement !== target) target.appendChild(categories);
        document.querySelectorAll('.store-responsive-panel').forEach(function (panel) {
            panel.open = !layoutMedia.matches;
            const summary = panel.querySelector('summary');
            if (summary) summary.onclick = function (event) { if (!layoutMedia.matches) event.preventDefault(); };
        });
    };
    updateLayout();
    if (layoutMedia.addEventListener) layoutMedia.addEventListener('change', updateLayout);
    else layoutMedia.addListener(updateLayout);
    const currency = () => typeof format !== 'undefined' && format.currencySymbol ? format.currencySymbol() : '¥';
    const money = value => {
        const number = Number(value);
        if (!Number.isFinite(number) || number < 0) return '—';
        return number.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    };
    const updateUrl = (push) => {
        const path = category === 'recommend' ? '/products' : '/category/' + category;
        const target = new URL(storefront.url(path), location.origin);
        if (push) history.pushState({}, '', target.pathname + target.search);
        else history.replaceState({}, '', target.pathname + target.search);
        storefront.syncLocaleLinks();
        const canonical = document.querySelector('link[rel="canonical"]');
        if (canonical) canonical.href = new URL(storefront.url(path), location.origin).href;
    };
    const render = function () {
        const filtered = products.filter(item => !search || plain(item.name).toLocaleLowerCase().includes(search.toLocaleLowerCase()));
        list.setAttribute('aria-busy', 'false');
        if (!filtered.length) {
            list.innerHTML = '<div class="store-list-message">' + escape(t(search ? '没有找到相关商品' : '暂无商品')) + '</div>';
            return;
        }
        list.innerHTML = filtered.map(function (item) {
            const name = plain(item.name);
            const soldOut = Number(item.stock_state) === 0 || (typeof item.stock_state === 'undefined' && Number(item.stock) === 0);
            const stock = soldOut ? t('已售罄') : Number(item.inventory_hidden) === 1 ? t(plain(item.stock)) || t('库存充足') : t('库存') + ' ' + plain(item.stock);
            const delivery = t(Number(item.delivery_way) === 0 ? '自动发货' : '在线发货');
            const quote = item.price;
            const tags = Array.isArray(item.tags) ? item.tags : [];
            const badges = tags.map(function (tag) {
                const color = ['red','orange','green','cyan','blue','purple','pink','gray'].includes(tag.color) ? tag.color : 'gray';
                return tag.text ? '<span class="acg-tag acg-tag--' + color + '">' + escape(plain(tag.text)) + '</span>' : '';
            }).join('');
            const href = storefront.url('/buy/' + Number(item.id));
            const salesLabel = item.sales_display_configured ? t('展示销量') : t('销量');
            const recentPaid = Math.max(0, Math.floor(Number(item.recent_paid_sales) || 0));
            const recentBadge = '<span class="store-sales-growth"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 17 6-6 4 4 8-10M15 5h6v6"/></svg><span>' + escape(t('近1小时售出')) + ' <b>' + recentPaid + '</b> ' + escape(t('件')) + '</span></span>';
            return '<a class="store-product' + (soldOut ? ' is-soldout' : '') + '" href="' + escape(href) + '" title="' + escape(name) + '">' +
                '<span class="store-product-visual"><img class="store-product-icon" src="' + escape(safeImage(item.cover)) + '" alt="" loading="lazy" decoding="async"></span>' +
                '<div class="store-product-info"><div class="store-product-tags">' + badges + (Number(item.recommend) === 1 ? '<span class="store-recommend-tag">' + escape(t('推荐')) + '</span>' : '') + '</div>' +
                '<div class="store-product-title">' + escape(name) + '</div>' +
                '<div class="store-product-meta"><span class="store-product-fulfillment"><i class="store-stock-dot" aria-hidden="true"></i><span>' + escape(delivery) + '</span><span aria-hidden="true">·</span><span class="' + (soldOut ? 'store-stock-empty' : '') + '">' + escape(stock) + '</span></span><span class="store-product-sales">' + escape(salesLabel) + ' ' + escape(item.sales == null ? '—' : item.sales) + '</span>' + recentBadge + '</div></div>' +
                '<div class="store-product-price"><strong data-display-base-amount="' + escape(quote) + '"><small>' + escape(currency()) + '</small>' + escape(money(quote)) + '</strong>' +
                '</div><svg class="store-product-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>';
        }).join('');
        window.StorePriceDisplay?.refresh();
    };
    const load = async function () {
        const request = ++sequence;
        if (activeRequest) activeRequest.abort();
        activeRequest = typeof AbortController === 'function' ? new AbortController() : null;
        list.setAttribute('aria-busy', 'true');
        const preserveServerProducts = category === serverCategory
            && !!list.querySelector('[data-server-product]');
        if (preserveServerProducts) list.querySelectorAll('[data-server-error]').forEach(node => node.remove());
        else list.innerHTML = '<div class="store-list-message">' + escape(t('正在加载商品')) + '</div>';
        document.querySelectorAll('[data-category]').forEach(function (link) {
            const selected = link.dataset.category === category;
            link.classList.toggle('is-active', selected);
            if (selected) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        if (catalogueTitle) {
            const selectedLabel = document.querySelector('.store-category.is-active > span:last-child');
            catalogueTitle.textContent = selectedLabel ? selectedLabel.textContent.trim() : t('全部商品');
        }
        try {
            const endpoint = new URL(storefront.apiUrl('/user/api/index/commodity'), location.origin);
            endpoint.searchParams.set('categoryId', category === 'recommend' ? '0' : category);
            const response = await fetch(endpoint.pathname + endpoint.search, {credentials: 'same-origin', signal: activeRequest ? activeRequest.signal : undefined, headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error(t('商品加载失败，请重试'));
            const result = await response.json();
            if (request !== sequence || !document.contains(list)) return;
            if (result.code !== 200 || !Array.isArray(result.data)) throw new Error(result.msg || t('商品加载失败，请重试'));
            products = result.data;
            render();
        } catch (error) {
            if (error.name === 'AbortError' || request !== sequence || !document.contains(list)) return;
            list.setAttribute('aria-busy', 'false');
            const failure = '<div class="store-list-message" data-server-error role="alert">' + escape(t('商品加载失败，请重试')) + '<button type="button" class="store-retry">' + escape(t('重试')) + '</button></div>';
            if (preserveServerProducts) list.insertAdjacentHTML('beforeend', failure);
            else list.innerHTML = failure;
            const retry = list.querySelector('.store-retry');
            if (retry) retry.onclick = load;
        }
    };
    document.querySelectorAll('[data-category]').forEach(function (link) {
        link.onclick = function (event) {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            category = link.dataset.category;
            search = '';
            if (searchInput) searchInput.value = '';
            updateUrl(true);
            load();
        };
    });
    const form = document.querySelector('.store-search-form');
    if (form) form.onsubmit = function (event) { event.preventDefault(); search = searchInput.value.trim(); render(); };
    if (searchInput) searchInput.oninput = function () { search = searchInput.value.trim(); render(); };
    const popstate = function () {
        if (!document.contains(list)) { window.removeEventListener('popstate', popstate); return; }
        const match = location.pathname.match(/\/category\/(\d+)(?:\.html)?\/?$/);
        category = match ? match[1] : 'recommend';
        clearPriceFilter();
        search = ''; if (searchInput) searchInput.value = ''; load();
    };
    window.addEventListener('popstate', popstate);
    if (typeof window.storeBannerCleanup === 'function') window.storeBannerCleanup();
    const banner = document.querySelector('.store-banner');
    const catalogCard = document.querySelector('.store-catalog-card');
    const catalogToolbar = document.querySelector('.store-catalog-toolbar');
    const slides = Array.from(document.querySelectorAll('.store-slide'));
    let slideIndex = 0;
    let slideTimer = null;
    let bannerHovered = false;
    let bannerFocused = false;
    let toolbarObserver = null;
    let slidesAlive = true;
    let pageSuspended = false;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const measureToolbar = function () {
        if (!catalogCard || !catalogToolbar) return;
        if (!document.contains(banner)) { cleanupSlides(); return; }
        const height = catalogToolbar.getBoundingClientRect().height;
        if (Number.isFinite(height) && height > 0 && height < 2048) catalogCard.style.setProperty('--store-toolbar-height', height + 'px');
    };
    const showSlide = function (index) {
        if (!slides.length) return;
        slideIndex = (index + slides.length) % slides.length;
        slides.forEach(function (slide, i) { slide.hidden = i !== slideIndex; slide.classList.toggle('is-active', i === slideIndex); });
        const isImage = !!slides[slideIndex].querySelector('[data-banner-image]');
        if (banner) {
            banner.classList.toggle('store-banner--text', !isImage);
            banner.classList.toggle('store-banner--image', isImage);
        }
        if (catalogCard) catalogCard.classList.toggle('store-catalog-card--image', isImage);
        document.querySelectorAll('[data-slide-index]').forEach(function (button, i) { button.setAttribute('aria-pressed', String(i === slideIndex)); });
    };
    const stopSlides = function () { if (slideTimer !== null) clearTimeout(slideTimer); slideTimer = null; };
    const scheduleSlides = function () {
        stopSlides();
        if (!slidesAlive || pageSuspended || !banner || slides.length < 2 || document.hidden || bannerHovered || bannerFocused || reducedMotion.matches) return;
        if (!document.contains(banner)) { cleanupSlides(); return; }
        slideTimer = setTimeout(function () {
            slideTimer = null;
            if (!document.contains(banner)) { cleanupSlides(); return; }
            showSlide(slideIndex + 1);
            scheduleSlides();
        }, 5000);
    };
    const chooseSlide = function (index) { if (slidesAlive) { showSlide(index); scheduleSlides(); } };
    const pointerEnter = function (event) { if (event.pointerType === 'mouse') { bannerHovered = true; stopSlides(); } };
    const pointerLeave = function (event) { if (event.pointerType === 'mouse') { bannerHovered = false; scheduleSlides(); } };
    const focusIn = function (event) {
        bannerFocused = !!(event.target && event.target.matches(':focus-visible'));
        if (bannerFocused) stopSlides(); else scheduleSlides();
    };
    const focusOut = function (event) {
        bannerFocused = !!(event.relatedTarget && banner.contains(event.relatedTarget) && event.relatedTarget.matches(':focus-visible'));
        scheduleSlides();
    };
    const pageHide = function (event) {
        if (event.persisted) { pageSuspended = true; stopSlides(); }
        else cleanupSlides();
    };
    const pageShow = function (event) {
        if (!event.persisted || !slidesAlive) return;
        pageSuspended = false;
        if (!document.contains(banner)) { cleanupSlides(); return; }
        bannerHovered = banner.matches(':hover');
        const focused = document.activeElement;
        bannerFocused = !!(focused && banner.contains(focused) && focused.matches(':focus-visible'));
        measureToolbar();
        scheduleSlides();
    };
    const cleanupSlides = function () {
        if (!slidesAlive) return;
        slidesAlive = false;
        stopSlides();
        document.removeEventListener('visibilitychange', scheduleSlides);
        window.removeEventListener('pagehide', pageHide);
        window.removeEventListener('pageshow', pageShow);
        window.removeEventListener('resize', measureToolbar);
        if (toolbarObserver) { toolbarObserver.disconnect(); toolbarObserver = null; }
        if (reducedMotion.removeEventListener) reducedMotion.removeEventListener('change', scheduleSlides);
        if (banner) {
            banner.removeEventListener('pointerenter', pointerEnter);
            banner.removeEventListener('pointerleave', pointerLeave);
            banner.removeEventListener('focusin', focusIn);
            banner.removeEventListener('focusout', focusOut);
        }
        if (window.storeBannerCleanup === cleanupSlides) delete window.storeBannerCleanup;
    };
    document.querySelectorAll('[data-slide-index]').forEach(button => { button.onclick = () => chooseSlide(Number(button.dataset.slideIndex)); });
    if (banner && slides.length > 1) {
        banner.addEventListener('pointerenter', pointerEnter);
        banner.addEventListener('pointerleave', pointerLeave);
        banner.addEventListener('focusin', focusIn);
        banner.addEventListener('focusout', focusOut);
        document.addEventListener('visibilitychange', scheduleSlides);
        if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', scheduleSlides);
    }
    if (banner) {
        window.addEventListener('pagehide', pageHide);
        window.addEventListener('pageshow', pageShow);
        window.storeBannerCleanup = cleanupSlides;
        if (catalogCard && catalogToolbar && slides.some(slide => slide.querySelector('[data-banner-image]'))) {
            measureToolbar();
            if (window.ResizeObserver) {
                toolbarObserver = new window.ResizeObserver(measureToolbar);
                toolbarObserver.observe(catalogToolbar);
            } else window.addEventListener('resize', measureToolbar);
        }
    }
    showSlide(0);
    scheduleSlides();
    load();
}());
