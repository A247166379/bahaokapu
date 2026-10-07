(() => {
    'use strict';
    if (typeof window.StoreBannerOverlayCleanup === 'function') window.StoreBannerOverlayCleanup();
    const banner = document.querySelector('.store-banner');
    const dialog = document.getElementById('store-banner-details-dialog');
    if (!banner || !dialog) return;
    const heading = dialog.querySelector('#store-banner-details-title');
    const description = dialog.querySelector('.store-banner-details-description');
    const closeButton = dialog.querySelector('.store-banner-details-close');
    if (!heading || !description || !closeButton) return;
    const defaultHeading = heading.textContent;
    let alive = true;
    let opener = null;
    let observer = null;
    const text = (root, selector) => root.querySelector(selector)?.textContent || '';
    const restoreFocus = () => {
        if (!alive) return;
        const target = opener?.isConnected && !opener.closest('[hidden]') ? opener
            : banner.querySelector('.store-slide:not([hidden]) .store-banner-overlay-action, [data-slide-index][aria-pressed="true"]');
        opener = null;
        target?.focus({preventScroll: true});
    };
    const close = () => {
        if (!dialog.open) return;
        if (typeof dialog.close === 'function') dialog.close();
        else { dialog.removeAttribute('open'); restoreFocus(); }
        dialog.classList.remove('is-fallback');
    };
    const openDetails = event => {
        const button = event.target.closest?.('button[data-banner-details]');
        if (!alive || !button || !banner.contains(button) || dialog.open) return;
        const overlay = button.closest('[data-banner-overlay]');
        if (!overlay || overlay.closest('[hidden]')) return;
        event.preventDefault();
        opener = button;
        // These nodes contain the complete, HTML-escaped plaintext supplied by
        // the template. CSS clamping does not truncate their textContent.
        heading.textContent = text(overlay, '.store-banner-overlay-title') || text(overlay, '.store-banner-overlay-eyebrow') || defaultHeading;
        description.textContent = text(overlay, '.store-banner-overlay-description');
        description.hidden = !description.textContent;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else { dialog.setAttribute('open', ''); dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.classList.add('is-fallback'); }
        closeButton.focus({preventScroll: true});
    };
    const cancel = event => { event.preventDefault(); close(); };
    const backdrop = event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
    };
    const keydown = event => {
        if (!dialog.open) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        else if (event.key === 'Tab' && dialog.classList.contains('is-fallback')) { event.preventDefault(); closeButton.focus({preventScroll: true}); }
    };
    const pageHide = event => { if (!event.persisted) cleanup(); };
    const cleanup = () => {
        if (!alive) return;
        alive = false;
        banner.removeEventListener('click', openDetails);
        closeButton.removeEventListener('click', close);
        dialog.removeEventListener('cancel', cancel);
        dialog.removeEventListener('close', restoreFocus);
        dialog.removeEventListener('click', backdrop);
        document.removeEventListener('keydown', keydown);
        window.removeEventListener('pagehide', pageHide);
        observer?.disconnect();
        if (window.jQuery) window.jQuery(document).off('.storeBannerOverlay');
        close();
        opener = null;
        if (window.StoreBannerOverlayCleanup === cleanup) delete window.StoreBannerOverlayCleanup;
    };
    banner.addEventListener('click', openDetails);
    closeButton.addEventListener('click', close);
    dialog.addEventListener('cancel', cancel);
    dialog.addEventListener('close', restoreFocus);
    dialog.addEventListener('click', backdrop);
    document.addEventListener('keydown', keydown);
    window.addEventListener('pagehide', pageHide);
    if (window.jQuery) window.jQuery(document).off('.storeBannerOverlay').on('pjax:beforeReplace.storeBannerOverlay admin:page:destroy.storeBannerOverlay', cleanup);
    if (typeof MutationObserver === 'function') {
        observer = new MutationObserver(() => { if (!banner.isConnected || !dialog.isConnected) cleanup(); });
        observer.observe(document.documentElement, {childList: true, subtree: true});
    }
    window.StoreBannerOverlayCleanup = cleanup;
})();
