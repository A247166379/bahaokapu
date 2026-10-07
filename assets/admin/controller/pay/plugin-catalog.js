!function () {
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const translate = value => typeof i18n === 'function' ? i18n(value) : value;
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };
    let unmount = null;

    function destroy() {
        if (unmount) unmount();
    }

    function mount(root, list, {onConfigured} = {}) {
        destroy();
        const element = typeof root === 'string' ? document.querySelector(root) : root?.jquery ? root[0] : root;
        if (!element) return () => {};

        const plugins = Array.isArray(list) ? list.filter(item => item && item.id != null) : [];
        // Render plugin metadata only. Configuration values belong to the existing editor.
        element.innerHTML = plugins.length ? `<div class="pay-plugin-catalog">${plugins.map((plugin, index) => {
            const name = plugin?.info?.name || plugin?.name || plugin.id;
            const options = plugin?.info?.options;
            const methods = options && typeof options === 'object'
                ? [...new Set(Object.values(options).filter(value => typeof value === 'string' && value.trim()))]
                : [];
            const configurable = Array.isArray(plugin.submit)
                ? plugin.submit.length > 0
                : typeof plugin.submit === 'string' && plugin.submit.trim() !== '';
            return `<article class="pay-plugin-catalog__item">
                <img class="pay-plugin-catalog__icon" src="${escapeHtml(safeImageUrl(plugin.icon))}" alt="">
                <div class="pay-plugin-catalog__text">
                    <h3 class="pay-plugin-catalog__name">${escapeHtml(name)}</h3>
                    ${methods.length ? `<p class="pay-plugin-catalog__methods">${escapeHtml(translate('支持'))}：${escapeHtml(methods.join('、'))}</p>` : ''}
                </div>
                <button class="btn btn-sm btn-light-primary pay-plugin-catalog__configure" type="button" data-plugin-index="${index}"${configurable ? '' : ' disabled'}>${escapeHtml(translate(configurable ? '配置插件' : '无需配置'))}</button>
            </article>`;
        }).join('')}</div>` : `<div class="pay-plugin-catalog__empty">${escapeHtml(translate('暂无已安装的支付插件'))}</div>`;

        const onClick = event => {
            const button = event.target.closest('[data-plugin-index]');
            if (!button || !element.contains(button) || button.disabled) return;
            const plugin = plugins[Number(button.dataset.pluginIndex)];
            if (!plugin) return;
            if (!window.AdminPayPluginConfig?.open(plugin)) {
                message.error(translate('支付插件配置未加载，请刷新页面重试'));
            }
        };
        const onSaved = event => {
            if (!plugins.some(plugin => String(plugin.id) === String(event.detail?.handle))) return;
            if (typeof onConfigured === 'function') onConfigured(event.detail);
        };
        element.addEventListener('click', onClick);
        window.addEventListener('admin:pay-plugin-config-saved', onSaved);

        let mounted = true;
        const cleanup = () => {
            if (!mounted) return;
            mounted = false;
            element.removeEventListener('click', onClick);
            window.removeEventListener('admin:pay-plugin-config-saved', onSaved);
            if (unmount === cleanup) unmount = null;
        };
        unmount = cleanup;
        return cleanup;
    }

    if (window.AdminPayPluginCatalog?.destroy) window.AdminPayPluginCatalog.destroy();
    window.AdminPayPluginCatalog = {mount, destroy};
}();
