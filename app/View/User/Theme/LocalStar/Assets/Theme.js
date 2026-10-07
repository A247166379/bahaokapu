(function () {
    'use strict';
    const body = document.body;
    const locale = body.dataset.storeLocale || (typeof getVar === 'function' && getVar('STORE_LOCALE')) || 'zh-cn';
    const prefix = body.dataset.storePrefix || (typeof getVar === 'function' && getVar('STORE_PREFIX')) || '';
    const localePrefixes = ['zh-cn', 'zh-tw', 'en', 'ru', 'vi'];
    const safePath = function (path) {
        const value = String(path || '/');
        if (!value.startsWith('/') || value.startsWith('//') || /[\\\x00-\x20]/.test(value)) return '';
        return value.replace(/^\/(?:zh-cn|zh-tw|en|ru|vi)(?=\/|\?|#|$)/, '') || '/';
    };
    // Match the server's public-page allowlist; machine/payment/credential URLs
    // never receive a .html suffix through this presentation helper.
    const publicPath = function (path) {
        path = path.replace(/\/+$/, '') || '/';
        let match = path.match(/^\/(?:buy|item)\/(\d+)(?:\.html)?$/);
        if (match) return '/buy/' + match[1] + '.html';
        match = path.match(/^\/(?:category|cat)\/(\d+|recommend)(?:\.html)?$/);
        if (match) return match[1] === 'recommend' ? '/products.html' : '/category/' + match[1] + '.html';
        match = path.match(/^\/(help|articles|terms)\/([a-z0-9][a-z0-9_-]{0,95})(?:\.html)?$/);
        if (match) return '/' + (match[1] === 'articles' ? 'help' : match[1]) + '/' + match[2] + '.html';
        const aliases = {'/':'/index.html','/index':'/index.html','/index.html':'/index.html','/user/index/index':'/index.html',
            '/products':'/products.html','/products.html':'/products.html','/order':'/order.html','/order.html':'/order.html','/user/index/query':'/order.html',
            '/help':'/help.html','/help.html':'/help.html','/articles':'/help.html','/articles.html':'/help.html','/user/store/help':'/help.html','/user/store/articles':'/help.html',
            '/contact':'/contact.html','/contact.html':'/contact.html','/user/store/contact':'/contact.html',
            '/sitemap':'/sitemap.html','/sitemap.html':'/sitemap.html','/user/store/sitemapPage':'/sitemap.html',
            '/closed':'/closed.html','/closed.html':'/closed.html','/user/store/closed':'/closed.html'};
        return aliases[path] || path;
    };
    const localUrl = function (path, targetPrefix) {
        const normalized = safePath(path);
        if (!normalized) return '#';
        const match = normalized.match(/^([^?#]*)([\s\S]*)$/);
        const pathname = match[1] || '/';
        if (pathname === '/sitemap.xml' || /^\/(?:admin|plugin|assets|kernel|user\/api|user\/captcha|user\/pay)(?:\/|$)/.test(pathname)) return normalized;
        return targetPrefix + publicPath(pathname) + match[2];
    };
    const url = path => localUrl(path, prefix);
    const t = function (key, fallback) {
        const value = typeof i18n === 'function' ? i18n(key) : key;
        return String(value === key && fallback != null ? fallback : value);
    };
    const apiUrl = function (path) {
        const normalized = safePath(path);
        if (!normalized) throw new Error('Invalid API path');
        const endpoint = new URL(normalized, location.origin);
        endpoint.searchParams.set('lang', locale);
        return endpoint.pathname + endpoint.search;
    };
    const syncLocaleLinks = function () {
        document.querySelectorAll('.store-language a[lang]').forEach(function (link) {
            const code = link.getAttribute('lang');
            if (!localePrefixes.includes(code)) return;
            const targetPrefix = code === 'zh-cn' ? '' : '/' + code;
            const path = safePath(location.pathname);
            const context = new URLSearchParams();
            const current = new URLSearchParams(location.search);
            ['price_type', 'race', 'quantity'].forEach(function (key) {
                const value = current.get(key);
                if (value !== null && value.length <= 128) context.set(key, value);
            });
            link.href = localUrl(path, targetPrefix) + (context.size ? '?' + context.toString() : '') + location.hash;
        });
    };
    window.Storefront = Object.assign(window.Storefront || {}, {url, apiUrl, t, locale, prefix, syncLocaleLinks});
    const configuredAppearance = body.dataset.storeDefaultAppearance || 'system';
    let appearance = ['system', 'light', 'gray', 'dark'].includes(configuredAppearance) ? configuredAppearance : 'system';
    try {
        const savedAppearance = localStorage.getItem('store-appearance');
        if (['system', 'light', 'gray', 'dark'].includes(savedAppearance)) {
            appearance = savedAppearance;
        }
    } catch (_) { /* Storage may be unavailable. */ }
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    // Newly shipped UI labels must work before a cached server dictionary is
    // rebuilt. A site's reviewed dictionary entry still takes precedence.
    const appearanceLabels = {
        'zh-tw': {'白色': '白色', '浅灰色': '淺灰色', '深灰色': '深灰色', '跟随访客设备': '跟隨訪客裝置'},
        en: {'白色': 'White', '浅灰色': 'Light gray', '深灰色': 'Dark gray', '跟随访客设备': 'Follow device'},
        ru: {'白色': 'Белая тема', '浅灰色': 'Светло-серая тема', '深灰色': 'Тёмно-серая тема', '跟随访客设备': 'Как на устройстве'},
        vi: {'白色': 'Trắng', '浅灰色': 'Xám nhạt', '深灰色': 'Xám đậm', '跟随访客设备': 'Theo thiết bị'}
    };
    const applyAppearance = function () {
        document.documentElement.dataset.storeTheme = appearance === 'system' ? (media.matches ? 'dark' : 'light') : appearance;
        const button = document.querySelector('.store-theme-toggle');
        if (button) {
            const key = appearance === 'system' ? '跟随访客设备' : appearance === 'light' ? '白色' : appearance === 'gray' ? '浅灰色' : '深灰色';
            const label = t(key, appearanceLabels[locale]?.[key]);
            button.title = label;
            button.setAttribute('aria-label', t('切换外观') + ': ' + label);
            button.dataset.appearance = appearance;
        }
        document.dispatchEvent(new CustomEvent('store:appearancechange', {detail: {theme: document.documentElement.dataset.storeTheme}}));
    };
    const logoColors = new Map();
    const inspectBrandLogos = function () {
        document.querySelectorAll('.store-header .ls-brand__mark img, .store-footer-logo img').forEach(function (image) {
            const inspect = function () {
                const source = image.currentSrc || image.src;
                image.removeAttribute('data-store-logo-monochrome');
                try {
                    if (new URL(source, location.href).origin !== location.origin || !image.naturalWidth) return;
                    if (!logoColors.has(source)) {
                        const canvas = document.createElement('canvas');
                        canvas.width = canvas.height = 128;
                        const context = canvas.getContext('2d', {willReadFrequently: true});
                        if (!context) return;
                        context.drawImage(image, 0, 0, 128, 128);
                        const pixels = context.getImageData(0, 0, 128, 128).data;
                        let transparent = 0, visible = 0, monochrome = true;
                        for (let i = 0; i < pixels.length; i += 4) {
                            if (pixels[i + 3] < 16) transparent++;
                            if (pixels[i + 3] < 16) continue;
                            visible++;
                            const high = Math.max(pixels[i], pixels[i + 1], pixels[i + 2]);
                            const low = Math.min(pixels[i], pixels[i + 1], pixels[i + 2]);
                            if (high > 48 || high - low > 2) monochrome = false;
                        }
                        logoColors.set(source, monochrome && visible > 4 && transparent > 10);
                    }
                    if (logoColors.get(source)) image.setAttribute('data-store-logo-monochrome', '');
                } catch (_) { /* Keep colored or unreadable images unchanged. */ }
            };
            if (image.complete) inspect();
            image.addEventListener('load', inspect, {once: true});
        });
    };
    const init = function () {
        applyAppearance();
        inspectBrandLogos();
        syncLocaleLinks();
        document.querySelectorAll('[data-localize-link]').forEach(function (link) {
            const href = link.getAttribute('href') || '';
            if (safePath(href)) link.href = url(href);
        });
        const nav = document.querySelector('.ls-navigation');
        const toggle = document.querySelector('.ls-menu-toggle');
        if (toggle && nav) toggle.onclick = function () {
            const opened = !nav.classList.contains('is-open');
            nav.classList.toggle('is-open', opened);
            toggle.setAttribute('aria-expanded', String(opened));
        };
        const themeButton = document.querySelector('.store-theme-toggle');
        if (themeButton) themeButton.onclick = function () {
            // Keep three simple choices; device mode alone resolves to OS dark.
            appearance = appearance === 'light' ? 'gray' : appearance === 'gray' ? 'dark' : appearance === 'dark' ? 'system' : 'light';
            try { localStorage.setItem('store-appearance', appearance); } catch (_) { /* Storage may be unavailable. */ }
            applyAppearance();
        };
        document.querySelectorAll('.store-faq details').forEach(function (detail) {
            detail.ontoggle = function () {
                if (detail.open) document.querySelectorAll('.store-faq details').forEach(function (other) { if (other !== detail) other.open = false; });
            };
        });
        document.querySelectorAll('[data-copy-value]').forEach(function (button) {
            button.onclick = async function () {
                const value = button.dataset.copyValue || '';
                try {
                    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(value);
                    else if (typeof util !== 'undefined' && typeof util.copyTextToClipboard === 'function') {
                        util.copyTextToClipboard(value, function () { if (typeof message !== 'undefined') message.success(t('已复制')); }, function () { if (typeof message !== 'undefined') message.error(t('复制失败，请手动复制')); });
                        return;
                    } else throw new Error('Clipboard unavailable');
                    if (typeof message !== 'undefined') message.success(t('已复制'));
                } catch (_) { if (typeof message !== 'undefined') message.error(t('复制失败，请手动复制')); }
            };
        });
        const path = publicPath(safePath(location.pathname));
        document.querySelectorAll('.ls-nav__link').forEach(function (link) {
            const destination = new URL(link.href, location.origin);
            const target = publicPath(safePath(destination.pathname));
            const section = target.replace(/\.html$/, '');
            const current = destination.origin === location.origin && (target === '/index.html' ? path === '/index.html' || path === '/products.html' || path.startsWith('/category/') : path === target || path.startsWith(section + '/'));
            link.classList.toggle('active', current);
            if (current) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
    };
    if (!window.__storeThemeEventsBound) {
        window.__storeThemeEventsBound = true;
        document.addEventListener('click', function (event) {
            const languageLink = event.target.closest('.store-language a[lang]');
            if (languageLink) {
                const code = languageLink.getAttribute('lang');
                try {
                    const target = new URL(languageLink.href, location.origin);
                    if (localePrefixes.includes(code) && target.origin === location.origin) {
                        document.cookie = 'store_locale_choice=' + encodeURIComponent(code) + '; Path=/; Max-Age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
                    }
                } catch (_) { /* A blocked cookie must never interrupt language-link navigation. */ }
            }
            const details = document.querySelector('.store-language');
            if (details && !details.contains(event.target)) details.open = false;
            const nav = document.querySelector('.ls-navigation');
            const toggle = document.querySelector('.ls-menu-toggle');
            if (nav && toggle && window.innerWidth < 900 && !event.target.closest('.store-header')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            const details = document.querySelector('.store-language');
            if (details) details.open = false;
        });
        if (media.addEventListener) media.addEventListener('change', applyAppearance);
        else if (media.addListener) media.addListener(applyAppearance);
        window.addEventListener('popstate', syncLocaleLinks);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true}); else init();
    if (window.jQuery) $(document).off('pjax:end.storeTheme').on('pjax:end.storeTheme', init);
}());
