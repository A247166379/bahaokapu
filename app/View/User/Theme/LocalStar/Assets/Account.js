/* Buyer navigation, shared appearance and purchase-table messages only. */
!function () {
    const doc = document;
    function syncAppearance() {
        const theme = doc.documentElement.getAttribute('data-store-theme');
        doc.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
    }
    function activateNavigation() {
        const path = location.pathname.replace(/^\/(?:zh-cn|zh-tw|en|ru|vi)(?=\/|$)/, '').replace(/\/+$/, '');
        doc.querySelectorAll('.store-account .uc-nav__item[data-match]').forEach(link => {
            const match = link.dataset.match;
            const active = path === match || path.startsWith(match + '/');
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
    }
    function tableMessages() {
        const $ = window.jQuery;
        if (!$?.fn?.bootstrapTable) return;
        $.extend($.fn.bootstrapTable.defaults, {
            formatNoMatches: () => '<div class="uc-empty">' + i18n('暂无数据') + '</div>',
            formatLoadingMessage: () => i18n('正在加载'),
            formatShowingRows: (from, to, total) => total > 0 ? i18n('显示第 {from} - {to} 条，共 {total} 条').replace('{from}', from).replace('{to}', to).replace('{total}', total) : i18n('共 0 条'),
            formatRecordsPerPage: number => i18n('每页 {n} 条').replace('{n}', number)
        });
    }
    syncAppearance(); activateNavigation(); tableMessages();
    if (window.MutationObserver) new MutationObserver(syncAppearance).observe(doc.documentElement, {attributes: true, attributeFilter: ['data-store-theme']});
}();
